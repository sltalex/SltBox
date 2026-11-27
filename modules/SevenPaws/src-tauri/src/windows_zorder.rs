#![cfg(windows)]

use crate::AppSettings;
#[cfg(not(debug_assertions))]
use std::ffi::OsStr;
#[cfg(not(debug_assertions))]
use std::os::windows::ffi::OsStrExt;
#[cfg(not(debug_assertions))]
use std::path::Path;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};
use tauri::{AppHandle, Manager};
use windows_sys::Win32::Foundation::{CloseHandle, BOOL, HANDLE, HWND, LPARAM, RECT};
use windows_sys::Win32::System::Threading::{OpenProcess, WaitForSingleObject, PROCESS_SYNCHRONIZE};
#[cfg(not(debug_assertions))]
use windows_sys::Win32::System::Threading::GetCurrentProcess;
use windows_sys::Win32::UI::WindowsAndMessaging::{
    EnumWindows, GetClassNameW, GetForegroundWindow, GetWindowLongPtrW, GetWindowRect, IsIconic,
    GWL_EXSTYLE, WS_EX_TOPMOST,
};
#[cfg(not(feature = "win7"))]
use windows_sys::Win32::UI::WindowsAndMessaging::{
    SetWindowPos, HWND_TOPMOST, SWP_NOACTIVATE, SWP_NOMOVE, SWP_NOSIZE,
};

fn foreground_hwnd() -> HWND {
    unsafe { GetForegroundWindow() }
}

fn is_topmost(hwnd: HWND) -> bool {
    if hwnd.is_null() {
        return false;
    }
    let style = unsafe { GetWindowLongPtrW(hwnd, GWL_EXSTYLE) };
    style & WS_EX_TOPMOST as isize != 0
}

fn is_iconic(hwnd: HWND) -> bool {
    !hwnd.is_null() && unsafe { IsIconic(hwnd) != 0 }
}

fn is_ignored_shell_window(hwnd: HWND) -> bool {
    let mut buffer = [0u16; 64];
    let length = unsafe { GetClassNameW(hwnd, buffer.as_mut_ptr(), buffer.len() as i32) };
    if length <= 0 {
        return false;
    }
    let class_name = String::from_utf16_lossy(&buffer[..length as usize]);
    matches!(
        class_name.as_str(),
        "Shell_TrayWnd"
            | "Shell_SecondaryTrayWnd"
            | "Progman"
            | "WorkerW"
            | "Windows.UI.Core.CoreWindow"
            | "XamlExplorerHostIslandWindow"
            | "MultitaskingViewFrame"
            | "TaskListThumbnailWnd"
    )
}

fn rects_overlap(first: HWND, second: HWND) -> bool {
    let mut first_rect = RECT {
        left: 0,
        top: 0,
        right: 0,
        bottom: 0,
    };
    let mut second_rect = RECT {
        left: 0,
        top: 0,
        right: 0,
        bottom: 0,
    };
    unsafe {
        if GetWindowRect(first, &mut first_rect) == 0
            || GetWindowRect(second, &mut second_rect) == 0
        {
            return true;
        }
    }
    first_rect.left < second_rect.right
        && first_rect.right > second_rect.left
        && first_rect.top < second_rect.bottom
        && first_rect.bottom > second_rect.top
}

fn window_is_above(candidate: HWND, model: HWND) -> bool {
    if candidate.is_null() || model.is_null() || candidate == model {
        return false;
    }
    if is_ignored_shell_window(candidate) || !rects_overlap(candidate, model) {
        return false;
    }

    struct OrderContext {
        candidate: HWND,
        model: HWND,
        candidate_above: bool,
    }

    unsafe extern "system" fn enum_proc(hwnd: HWND, lparam: LPARAM) -> BOOL {
        let context = &mut *(lparam as *mut OrderContext);
        if hwnd == context.candidate {
            context.candidate_above = true;
            0
        } else if hwnd == context.model {
            0
        } else {
            1
        }
    }

    let mut context = OrderContext {
        candidate,
        model,
        candidate_above: false,
    };
    unsafe {
        EnumWindows(
            Some(enum_proc),
            (&mut context as *mut OrderContext) as LPARAM,
        );
    }
    context.candidate_above
}

#[cfg(feature = "win7")]
fn presenter_hwnd(_app: &AppHandle) -> Option<HWND> {
    crate::win7::presenter_hwnd()
}

#[cfg(not(feature = "win7"))]
fn presenter_hwnd(app: &AppHandle) -> Option<HWND> {
    app.get_webview_window("main")?
        .hwnd()
        .ok()
        .map(|handle| handle.0 as HWND)
}

#[cfg(feature = "win7")]
fn presenter_is_visible(_app: &AppHandle) -> bool {
    crate::win7::is_visible()
}

#[cfg(not(feature = "win7"))]
fn presenter_is_visible(app: &AppHandle) -> bool {
    app.get_webview_window("main")
        .and_then(|window| window.is_visible().ok())
        .unwrap_or(false)
}

#[cfg(feature = "win7")]
fn reassert_topmost(_app: &AppHandle) {
    crate::win7::reassert_topmost();
}

#[cfg(not(feature = "win7"))]
fn reassert_topmost(app: &AppHandle) {
    let app = app.clone();
    let _ = app.clone().run_on_main_thread(move || {
        let Some(window) = app.get_webview_window("main") else {
            return;
        };
        if !window.is_visible().unwrap_or(false) {
            return;
        }
        let Ok(handle) = window.hwnd() else {
            return;
        };
        unsafe {
            SetWindowPos(
                handle.0 as HWND,
                HWND_TOPMOST,
                0,
                0,
                0,
                0,
                SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE,
            );
        }
    });
}

fn settings_window_open(app: &AppHandle) -> bool {
    app.get_webview_window("settings")
        .and_then(|window| window.is_visible().ok())
        .unwrap_or(false)
}

#[cfg(not(debug_assertions))]
fn wide(value: &OsStr) -> Vec<u16> {
    value.encode_wide().chain(Some(0)).collect()
}

#[cfg(not(debug_assertions))]
#[link(name = "shell32")]
extern "system" {
    fn ShellExecuteW(
        window: HWND,
        operation: *const u16,
        file: *const u16,
        parameters: *const u16,
        directory: *const u16,
        show_command: i32,
    ) -> isize;
}

#[cfg(not(debug_assertions))]
#[link(name = "advapi32")]
extern "system" {
    fn OpenProcessToken(process: HANDLE, access: u32, token: *mut HANDLE) -> BOOL;
    fn GetTokenInformation(
        token: HANDLE,
        information_class: u32,
        information: *mut std::ffi::c_void,
        information_length: u32,
        return_length: *mut u32,
    ) -> BOOL;
}

#[cfg(not(debug_assertions))]
fn is_elevated() -> bool {
    #[repr(C)]
    struct TokenElevation {
        token_is_elevated: u32,
    }
    unsafe {
        let mut token = std::ptr::null_mut();
        if OpenProcessToken(GetCurrentProcess(), 0x0008, &mut token) == 0 || token.is_null() {
            return false;
        }
        let mut elevation = TokenElevation {
            token_is_elevated: 0,
        };
        let mut returned = 0u32;
        let ok = GetTokenInformation(
            token,
            20,
            (&mut elevation as *mut TokenElevation).cast(),
            std::mem::size_of::<TokenElevation>() as u32,
            &mut returned,
        );
        CloseHandle(token);
        ok != 0 && elevation.token_is_elevated != 0
    }
}

#[cfg(not(debug_assertions))]
fn launch_elevated(exe: &Path, parameters: &str) -> bool {
    let operation = wide(OsStr::new("runas"));
    let file = wide(exe.as_os_str());
    let parameters = wide(OsStr::new(parameters));
    let directory = exe.parent().map(|parent| wide(parent.as_os_str()));
    let directory = directory
        .as_ref()
        .map_or(std::ptr::null(), |value| value.as_ptr());
    let result = unsafe {
        ShellExecuteW(
            std::ptr::null_mut(),
            operation.as_ptr(),
            file.as_ptr(),
            parameters.as_ptr(),
            directory,
            1,
        )
    };
    result > 32
}

#[cfg(debug_assertions)]
pub fn is_elevated_now() -> bool {
    false
}

#[cfg(not(debug_assertions))]
pub fn is_elevated_now() -> bool {
    is_elevated()
}

#[cfg(debug_assertions)]
pub fn request_elevated_startup(_enabled: bool) -> bool {
    false
}

#[cfg(not(debug_assertions))]
pub fn request_elevated_startup(enabled: bool) -> bool {
    if !enabled || is_elevated() {
        return false;
    }
    let Ok(exe) = std::env::current_exe() else {
        return false;
    };
    launch_elevated(&exe, &format!("--wait-pid {}", std::process::id()))
}

pub fn wait_for_owner_exit(process_id: u32) {
    unsafe {
        let process: HANDLE = OpenProcess(PROCESS_SYNCHRONIZE, 0, process_id);
        if !process.is_null() {
            WaitForSingleObject(process, u32::MAX);
            CloseHandle(process);
        }
    }
}

pub fn wait_for_process_exit(process_id: u32) -> bool {
    if process_id == 0 {
        return false;
    }
    unsafe {
        let process: HANDLE = OpenProcess(PROCESS_SYNCHRONIZE, 0, process_id);
        if process.is_null() {
            return false;
        }
        let result = WaitForSingleObject(process, 15_000);
        CloseHandle(process);
        result == 0
    }
}

#[cfg(debug_assertions)]
pub fn restart_as_admin(_app: AppHandle) -> Result<String, String> {
    Err("开发模式不支持管理员重启，请使用 Windows 发布包验证".to_string())
}

#[cfg(not(debug_assertions))]
pub fn restart_as_admin(app: AppHandle) -> Result<String, String> {
    let exe = std::env::current_exe().map_err(|error| error.to_string())?;
    let process_id = std::process::id();
    if let Some(settings) = app.get_webview_window("settings") {
        let _ = settings.hide();
    }
    std::thread::spawn(move || {
        if launch_elevated(&exe, &format!("--wait-pid {process_id}")) {
            #[cfg(not(feature = "win7"))]
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.hide();
            }
            #[cfg(feature = "win7")]
            crate::win7::set_visible(false);
            app.exit(0);
        }
    });
    Ok("已发起管理员重启请求，请确认 UAC；取消后当前实例会继续运行".to_string())
}

pub fn start_guard(app: AppHandle, settings: Arc<Mutex<AppSettings>>, dragging: Arc<AtomicBool>) {
    thread::spawn(move || {
        let mut last_foreground = 0isize;
        let mut foreground_attempts = 0u32;
        let mut last_reassert = Instant::now() - Duration::from_secs(10);
        let mut last_enum_check = Instant::now() - Duration::from_secs(2);
        let mut foreground_just_changed = true;

        loop {
            thread::sleep(Duration::from_millis(150));

            if dragging.load(Ordering::Relaxed) || settings_window_open(&app) {
                last_foreground = 0;
                foreground_attempts = 0;
                foreground_just_changed = true;
                continue;
            }
            let always_on_top = settings
                .lock()
                .map(|settings| settings.always_on_top)
                .unwrap_or(true);
            if !always_on_top || !presenter_is_visible(&app) {
                last_foreground = 0;
                foreground_attempts = 0;
                foreground_just_changed = true;
                continue;
            }
            let Some(our_hwnd) = presenter_hwnd(&app) else {
                foreground_just_changed = true;
                continue;
            };
            if is_iconic(our_hwnd) {
                foreground_just_changed = true;
                continue;
            }

            let foreground = foreground_hwnd();
            if foreground as isize != last_foreground {
                last_foreground = foreground as isize;
                foreground_attempts = 0;
                foreground_just_changed = true;
                last_reassert = Instant::now() - Duration::from_secs(10);
            }

            let lost_topmost = !is_topmost(our_hwnd);
            // EnumWindows is the expensive part; run it on foreground changes, while
            // recovering, or occasionally as a safety net instead of every tick.
            let run_enum = foreground_just_changed
                || foreground_attempts > 0
                || last_enum_check.elapsed() >= Duration::from_millis(900);
            let foreground_above = !lost_topmost
                && run_enum
                && !foreground.is_null()
                && foreground != our_hwnd
                && window_is_above(foreground, our_hwnd);
            if run_enum {
                last_enum_check = Instant::now();
            }
            foreground_just_changed = false;

            if !lost_topmost && !foreground_above {
                if run_enum {
                    foreground_attempts = 0;
                }
                continue;
            }

            let cooldown = if lost_topmost {
                Duration::from_millis(250)
            } else {
                match foreground_attempts {
                    0 => Duration::ZERO,
                    1 => Duration::from_millis(350),
                    _ => Duration::from_millis(900),
                }
            };
            if last_reassert.elapsed() >= cooldown {
                reassert_topmost(&app);
                last_reassert = Instant::now();
                foreground_attempts = foreground_attempts.saturating_add(1);
            }
        }
    });
}

#[cfg(all(test, windows))]
mod tests {
    use super::{is_topmost, window_is_above};
    use std::ffi::OsStr;
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::Foundation::HWND;
    use windows_sys::Win32::System::LibraryLoader::GetModuleHandleW;
    use windows_sys::Win32::UI::WindowsAndMessaging::{
        CreateWindowExW, DestroyWindow, SetWindowPos, HWND_TOPMOST, SWP_NOACTIVATE, SWP_NOMOVE,
        SWP_NOSIZE, WS_EX_NOACTIVATE, WS_EX_TOOLWINDOW, WS_EX_TOPMOST, WS_POPUP,
    };

    fn wide(value: &str) -> Vec<u16> {
        OsStr::new(value).encode_wide().chain(Some(0)).collect()
    }

    fn create_window(title: &str) -> HWND {
        let class_name = wide("STATIC");
        let title = wide(title);
        unsafe {
            CreateWindowExW(
                WS_EX_TOPMOST | WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE,
                class_name.as_ptr(),
                title.as_ptr(),
                WS_POPUP,
                100,
                100,
                200,
                200,
                std::ptr::null_mut(),
                std::ptr::null_mut(),
                GetModuleHandleW(std::ptr::null()),
                std::ptr::null(),
            )
        }
    }

    #[test]
    fn detects_and_recovers_topmost_order_without_activation() {
        let model = create_window("7paws Z-order model");
        let foreground = create_window("7paws Z-order foreground");
        assert!(!model.is_null() && !foreground.is_null());
        unsafe {
            SetWindowPos(
                foreground,
                HWND_TOPMOST,
                0,
                0,
                0,
                0,
                SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE,
            );
            assert!(is_topmost(model));
            assert!(window_is_above(foreground, model));

            SetWindowPos(
                model,
                HWND_TOPMOST,
                0,
                0,
                0,
                0,
                SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE,
            );
            assert!(!window_is_above(foreground, model));

            DestroyWindow(foreground);
            DestroyWindow(model);
        }
    }
}
