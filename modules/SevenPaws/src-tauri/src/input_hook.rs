use crate::config::AppSettings;
use crate::keymap;
#[cfg(target_os = "macos")]
use crate::keymap_macos;
use serde::Serialize;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc;
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};
use tauri::{AppHandle, Emitter};
#[cfg(target_os = "macos")]
use tauri::Manager;

#[derive(Debug, Clone, Serialize)]
#[serde(tag = "kind", rename_all = "camelCase")]
pub enum CatInput {
    KeyDown {
        code: String,
        repeat: bool,
    },
    KeyUp {
        code: String,
    },
    MouseDown {
        button: String,
    },
    MouseUp {
        button: String,
    },
    MouseMoved {
        nx: f64,
        ny: f64,
    },
    MouseWheel {
        delta: i32,
    },
    PrivacyTap {
        side: String,
    },
}

#[cfg(target_os = "macos")]
#[derive(Debug, Clone, Serialize)]
pub struct InputStatus {
    pub trusted: bool,
}

enum RawHookMsg {
    KeyDown {
        vk: u32,
        scan: u32,
        extended: bool,
    },
    KeyUp {
        vk: u32,
        scan: u32,
        extended: bool,
    },
    MouseDown(&'static str),
    MouseUp(&'static str),
    MouseMoved { x: i32, y: i32 },
    MouseWheel { delta: i32 },
}

static HOOK_TX: OnceLock<mpsc::Sender<RawHookMsg>> = OnceLock::new();
static ALT_HELD: AtomicBool = AtomicBool::new(false);

fn is_alt_vk(vk: u32) -> bool {
    #[cfg(windows)]
    {
        matches!(vk, 0x12 | 0xA4 | 0xA5)
    }
    #[cfg(target_os = "macos")]
    {
        matches!(vk, 0x3A | 0x3D)
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = vk;
        false
    }
}

pub fn is_alt_pressed() -> bool {
    #[cfg(windows)]
    {
        use windows_sys::Win32::UI::Input::KeyboardAndMouse::{GetAsyncKeyState, VK_MENU};
        let async_down = unsafe { (GetAsyncKeyState(VK_MENU as i32) as u16 & 0x8000) != 0 };
        if async_down {
            return true;
        }
    }
    ALT_HELD.load(Ordering::Relaxed)
}

fn code_for_raw(vk: u32, scan: u32, extended: bool) -> &'static str {
    #[cfg(windows)]
    {
        keymap::code_for(vk, scan, extended)
    }
    #[cfg(target_os = "macos")]
    {
        let _ = (scan, extended);
        keymap_macos::code_for(vk as u16)
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = (vk, scan, extended);
        "Unidentified"
    }
}

pub fn start_global_hooks(app: AppHandle, settings: Arc<Mutex<AppSettings>>) {
    let (tx, rx) = mpsc::channel::<RawHookMsg>();
    let _ = HOOK_TX.set(tx);

    #[cfg(target_os = "macos")]
    let mac_app = app.clone();

    std::thread::spawn(move || {
        let mut pressed = [false; 1024];
        let mut pressed_at: [Instant; 1024] = [Instant::now(); 1024];
        let mut last_mouse_emit = Instant::now() - Duration::from_secs(1);
        let mut last_wheel_emit = Instant::now() - Duration::from_secs(1);
        #[cfg(windows)]
        let mut last_mouse_xy: Option<(i32, i32)> = None;

        while let Ok(msg) = rx.recv() {
            match msg {
                RawHookMsg::KeyDown {
                    vk,
                    scan,
                    extended,
                } => {
                    if is_alt_vk(vk) {
                        ALT_HELD.store(true, Ordering::Relaxed);
                    }
                    let privacy = settings
                        .lock()
                        .map(|s| s.privacy_mode)
                        .unwrap_or(false);
                    let idx = (vk as usize) % 1024;
                    let now = Instant::now();
                    let repeat = pressed[idx]
                        && now.duration_since(pressed_at[idx]) < Duration::from_secs(2);
                    if !repeat {
                        pressed[idx] = true;
                        pressed_at[idx] = now;
                    }
                    let code = code_for_raw(vk, scan, extended);
                    if privacy {
                        if !repeat && code != "Unidentified" {
                            let side = if keymap::is_left_hand(code) {
                                "Left"
                            } else {
                                "Right"
                            };
                            let _ = app.emit(
                                "cat-input",
                                CatInput::PrivacyTap {
                                    side: side.to_string(),
                                },
                            );
                        }
                    } else {
                        let _ = app.emit(
                            "cat-input",
                            CatInput::KeyDown {
                                code: code.to_string(),
                                repeat,
                            },
                        );
                    }
                }
                RawHookMsg::KeyUp {
                    vk,
                    scan,
                    extended,
                } => {
                    if is_alt_vk(vk) {
                        ALT_HELD.store(false, Ordering::Relaxed);
                    }
                    let privacy = settings
                        .lock()
                        .map(|s| s.privacy_mode)
                        .unwrap_or(false);
                    let idx = (vk as usize) % 1024;
                    pressed[idx] = false;
                    if !privacy {
                        let code = code_for_raw(vk, scan, extended);
                        let _ = app.emit(
                            "cat-input",
                            CatInput::KeyUp {
                                code: code.to_string(),
                            },
                        );
                    }
                }
                RawHookMsg::MouseDown(btn) => {
                    crate::close_settings_if_clicked_outside(&app);
                    let _ = app.emit(
                        "cat-input",
                        CatInput::MouseDown {
                            button: btn.to_string(),
                        },
                    );
                }
                RawHookMsg::MouseUp(btn) => {
                    let _ = app.emit(
                        "cat-input",
                        CatInput::MouseUp {
                            button: btn.to_string(),
                        },
                    );
                }
                RawHookMsg::MouseMoved { x, y } => {
                    // Windows/macOS sensor jitter otherwise wakes a resting cat and keeps it at full FPS.
                    // macOS reports 0,0 here, so the filter only applies where the point is real.
                    #[cfg(windows)]
                    if let Some((lx, ly)) = last_mouse_xy {
                        if (x - lx).abs() <= 1 && (y - ly).abs() <= 1 {
                            continue;
                        }
                    }
                    let now = Instant::now();
                    // 30 Hz is enough for the eye and paw springs; it halves the IPC of high-polling mice.
                    if now.duration_since(last_mouse_emit) >= Duration::from_millis(33) {
                        last_mouse_emit = now;
                        #[cfg(windows)]
                        {
                            last_mouse_xy = Some((x, y));
                        }
                        let (nx, ny) = normalize_window_point(&app, x, y);
                        let _ = app.emit("cat-input", CatInput::MouseMoved { nx, ny });
                    }
                }
                RawHookMsg::MouseWheel { delta } => {
                    let now = Instant::now();
                    if now.duration_since(last_wheel_emit) >= Duration::from_millis(24) {
                        last_wheel_emit = now;
                        let _ = app.emit("cat-input", CatInput::MouseWheel { delta });
                    }
                }
            }
        }
    });

    #[cfg(windows)]
    std::thread::spawn(run_windows_hook_loop);
    #[cfg(target_os = "macos")]
    std::thread::spawn(move || run_macos_hook_loop(mac_app));
}

#[cfg(windows)]
fn normalize_window_point(app: &AppHandle, x: i32, y: i32) -> (f64, f64) {
    if let Some((px, py, width, height)) = crate::main_window_rect(app) {
        let w = width.max(1) as f64;
        let h = height.max(1) as f64;
        return ((x - px) as f64 / w, (y - py) as f64 / h);
    }
    (0.5, 0.5)
}

// Match the target window's pixel scale, including mixed-DPI macOS displays.
#[cfg(target_os = "macos")]
fn normalize_window_point(app: &AppHandle, _x: i32, _y: i32) -> (f64, f64) {
    if let Some(win) = app.get_webview_window("main") {
        if let (Some((cx, cy)), Ok(pos), Ok(size)) = (
            crate::cursor_pos_for(&win),
            win.outer_position(),
            win.outer_size(),
        ) {
            let w = size.width.max(1) as f64;
            let h = size.height.max(1) as f64;
            return ((cx - pos.x as f64) / w, (cy - pos.y as f64) / h);
        }
    }
    (0.5, 0.5)
}

#[cfg(not(any(windows, target_os = "macos")))]
fn normalize_window_point(_app: &AppHandle, _x: i32, _y: i32) -> (f64, f64) {
    (0.5, 0.5)
}

#[cfg(windows)]
unsafe extern "system" fn keyboard_hook_proc(code: i32, wparam: usize, lparam: isize) -> isize {
    use windows_sys::Win32::UI::WindowsAndMessaging::{
        CallNextHookEx, KBDLLHOOKSTRUCT, WM_KEYDOWN, WM_KEYUP, WM_SYSKEYDOWN, WM_SYSKEYUP,
    };

    if code >= 0 {
        let kb = &*(lparam as *const KBDLLHOOKSTRUCT);
        let msg = wparam as u32;
        let extended = (kb.flags & 0x01) != 0;
        if let Some(tx) = HOOK_TX.get() {
            match msg {
                WM_KEYDOWN | WM_SYSKEYDOWN => {
                    let _ = tx.send(RawHookMsg::KeyDown {
                        vk: kb.vkCode,
                        scan: kb.scanCode,
                        extended,
                    });
                }
                WM_KEYUP | WM_SYSKEYUP => {
                    let _ = tx.send(RawHookMsg::KeyUp {
                        vk: kb.vkCode,
                        scan: kb.scanCode,
                        extended,
                    });
                }
                _ => {}
            }
        }
    }
    CallNextHookEx(std::ptr::null_mut(), code, wparam, lparam)
}

#[cfg(windows)]
unsafe extern "system" fn mouse_hook_proc(code: i32, wparam: usize, lparam: isize) -> isize {
    use windows_sys::Win32::UI::WindowsAndMessaging::{
        CallNextHookEx, MSLLHOOKSTRUCT, WM_LBUTTONDOWN, WM_LBUTTONUP, WM_MBUTTONDOWN,
        WM_MBUTTONUP, WM_MOUSEMOVE, WM_MOUSEWHEEL, WM_RBUTTONDOWN, WM_RBUTTONUP,
    };

    if code >= 0 {
        let ms = &*(lparam as *const MSLLHOOKSTRUCT);
        let msg = wparam as u32;
        if let Some(tx) = HOOK_TX.get() {
            match msg {
                WM_MOUSEMOVE => {
                    let _ = tx.send(RawHookMsg::MouseMoved {
                        x: ms.pt.x,
                        y: ms.pt.y,
                    });
                }
                WM_LBUTTONDOWN => {
                    let _ = tx.send(RawHookMsg::MouseDown("left"));
                }
                WM_LBUTTONUP => {
                    let _ = tx.send(RawHookMsg::MouseUp("left"));
                }
                WM_RBUTTONDOWN => {
                    let _ = tx.send(RawHookMsg::MouseDown("right"));
                }
                WM_RBUTTONUP => {
                    let _ = tx.send(RawHookMsg::MouseUp("right"));
                }
                WM_MBUTTONDOWN => {
                    let _ = tx.send(RawHookMsg::MouseDown("middle"));
                }
                WM_MBUTTONUP => {
                    let _ = tx.send(RawHookMsg::MouseUp("middle"));
                }
                WM_MOUSEWHEEL => {
                    let delta = ((ms.mouseData >> 16) as u16 as i16) as i32;
                    if delta != 0 {
                        let _ = tx.send(RawHookMsg::MouseWheel { delta });
                    }
                }
                _ => {}
            }
        }
    }
    CallNextHookEx(std::ptr::null_mut(), code, wparam, lparam)
}

#[cfg(windows)]
fn run_windows_hook_loop() {
    use windows_sys::Win32::System::LibraryLoader::GetModuleHandleW;
    use windows_sys::Win32::UI::WindowsAndMessaging::{
        GetMessageW, SetWindowsHookExW, UnhookWindowsHookEx, MSG, WH_KEYBOARD_LL, WH_MOUSE_LL,
    };

    unsafe {
        let hmod = GetModuleHandleW(std::ptr::null());
        let kb_hook = SetWindowsHookExW(WH_KEYBOARD_LL, Some(keyboard_hook_proc), hmod, 0);
        let ms_hook = SetWindowsHookExW(WH_MOUSE_LL, Some(mouse_hook_proc), hmod, 0);

        let mut msg: MSG = std::mem::zeroed();
        while GetMessageW(&mut msg, std::ptr::null_mut(), 0, 0) > 0 {}

        if !kb_hook.is_null() {
            UnhookWindowsHookEx(kb_hook);
        }
        if !ms_hook.is_null() {
            UnhookWindowsHookEx(ms_hook);
        }
    }
}

#[cfg(target_os = "macos")]
mod accessibility {
    #[link(name = "ApplicationServices", kind = "framework")]
    unsafe extern "C" {
        fn AXIsProcessTrusted() -> u8;
        fn AXIsProcessTrustedWithOptions(options: *const std::ffi::c_void) -> u8;
    }

    #[link(name = "CoreGraphics", kind = "framework")]
    unsafe extern "C" {
        fn CGPreflightListenEventAccess() -> u8;
        fn CGRequestListenEventAccess() -> u8;
        fn CGPreflightPostEventAccess() -> u8;
    }

    // Since macOS 15, CGRequestListenEventAccess no longer reliably registers
    // the app in System Settings > Input Monitoring; the IOHID APIs do.
    #[link(name = "IOKit", kind = "framework")]
    unsafe extern "C" {
        fn IOHIDCheckAccess(request_type: u32) -> u32;
        fn IOHIDRequestAccess(request_type: u32) -> u8;
    }

    const K_IOHID_REQUEST_TYPE_LISTEN_EVENT: u32 = 1;
    const K_IOHID_ACCESS_GRANTED: u32 = 0;

    fn accessibility_granted() -> bool {
        unsafe { AXIsProcessTrusted() != 0 || CGPreflightPostEventAccess() != 0 }
    }

    fn input_monitoring_granted() -> bool {
        // CGPreflightListenEventAccess can stay false on macOS 15+ even when
        // the toggle is on, so accept either signal.
        unsafe {
            IOHIDCheckAccess(K_IOHID_REQUEST_TYPE_LISTEN_EVENT) == K_IOHID_ACCESS_GRANTED
                || CGPreflightListenEventAccess() != 0
        }
    }

    // Keyboard event taps need Accessibility plus Input Monitoring on modern
    // macOS. macOS may require a relaunch before the tap opens.
    pub fn is_trusted() -> bool {
        accessibility_granted() && input_monitoring_granted()
    }

    pub fn prompt() {
        use core_foundation::base::TCFType;
        use core_foundation::boolean::CFBoolean;
        use core_foundation::dictionary::CFDictionary;
        use core_foundation::string::CFString;

        let key = CFString::new("AXTrustedCheckOptionPrompt");
        let value = CFBoolean::true_value();
        let options: CFDictionary<CFString, CFBoolean> =
            CFDictionary::from_CFType_pairs(&[(key, value)]);
        unsafe {
            AXIsProcessTrustedWithOptions(options.as_concrete_TypeRef() as *const std::ffi::c_void);
            IOHIDRequestAccess(K_IOHID_REQUEST_TYPE_LISTEN_EVENT);
            CGRequestListenEventAccess();
        }
    }
}

#[cfg(target_os = "macos")]
fn forward_macos_event(
    etype: core_graphics::event::CGEventType,
    event: &core_graphics::event::CGEvent,
) {
    use core_graphics::event::{CGEventFlags, CGEventType, EventField};

    let Some(tx) = HOOK_TX.get() else {
        return;
    };
    let keycode = || event.get_integer_value_field(EventField::KEYBOARD_EVENT_KEYCODE) as u32;
    match etype {
        CGEventType::KeyDown => {
            let _ = tx.send(RawHookMsg::KeyDown {
                vk: keycode(),
                scan: 0,
                extended: false,
            });
        }
        CGEventType::KeyUp => {
            let _ = tx.send(RawHookMsg::KeyUp {
                vk: keycode(),
                scan: 0,
                extended: false,
            });
        }
        CGEventType::FlagsChanged => {
            let kc = keycode();
            let flags = event.get_flags();
            let down = match kc {
                0x38 | 0x3C => flags.contains(CGEventFlags::CGEventFlagShift),
                0x3B | 0x3E => flags.contains(CGEventFlags::CGEventFlagControl),
                0x3A | 0x3D => flags.contains(CGEventFlags::CGEventFlagAlternate),
                0x36 | 0x37 => flags.contains(CGEventFlags::CGEventFlagCommand),
                0x39 => flags.contains(CGEventFlags::CGEventFlagAlphaShift),
                0x3F => flags.contains(CGEventFlags::CGEventFlagSecondaryFn),
                _ => false,
            };
            let _ = tx.send(if down {
                RawHookMsg::KeyDown {
                    vk: kc,
                    scan: 0,
                    extended: false,
                }
            } else {
                RawHookMsg::KeyUp {
                    vk: kc,
                    scan: 0,
                    extended: false,
                }
            });
        }
        CGEventType::MouseMoved
        | CGEventType::LeftMouseDragged
        | CGEventType::RightMouseDragged
        | CGEventType::OtherMouseDragged => {
            let _ = tx.send(RawHookMsg::MouseMoved { x: 0, y: 0 });
        }
        CGEventType::LeftMouseDown => {
            let _ = tx.send(RawHookMsg::MouseDown("left"));
        }
        CGEventType::LeftMouseUp => {
            let _ = tx.send(RawHookMsg::MouseUp("left"));
        }
        CGEventType::RightMouseDown => {
            let _ = tx.send(RawHookMsg::MouseDown("right"));
        }
        CGEventType::RightMouseUp => {
            let _ = tx.send(RawHookMsg::MouseUp("right"));
        }
        CGEventType::OtherMouseDown | CGEventType::OtherMouseUp => {
            let button = event.get_integer_value_field(EventField::MOUSE_EVENT_BUTTON_NUMBER);
            let name = match button {
                2 => "middle",
                3 => "back",
                4 => "forward",
                _ => "other",
            };
            let _ = tx.send(if matches!(etype, CGEventType::OtherMouseDown) {
                RawHookMsg::MouseDown(name)
            } else {
                RawHookMsg::MouseUp(name)
            });
        }
        CGEventType::ScrollWheel => {
            let delta =
                event.get_integer_value_field(EventField::SCROLL_WHEEL_EVENT_DELTA_AXIS_1) as i32;
            if delta != 0 {
                let _ = tx.send(RawHookMsg::MouseWheel { delta });
            }
        }
        _ => {}
    }
}

#[cfg(target_os = "macos")]
fn run_macos_hook_loop(app: AppHandle) {
    use core_foundation::runloop::CFRunLoop;
    use core_graphics::event::{
        CGEventTap, CGEventTapLocation, CGEventTapOptions, CGEventTapPlacement, CGEventType,
        CallbackResult,
    };

    let events = vec![
        CGEventType::KeyDown,
        CGEventType::KeyUp,
        CGEventType::FlagsChanged,
        CGEventType::MouseMoved,
        CGEventType::LeftMouseDragged,
        CGEventType::RightMouseDragged,
        CGEventType::OtherMouseDragged,
        CGEventType::LeftMouseDown,
        CGEventType::LeftMouseUp,
        CGEventType::RightMouseDown,
        CGEventType::RightMouseUp,
        CGEventType::OtherMouseDown,
        CGEventType::OtherMouseUp,
        CGEventType::ScrollWheel,
    ];

    let mut last_reported: Option<bool> = None;
    let mut prompted = false;
    let mut report = |trusted: bool| {
        // Repeat the "missing permission" state so a webview that loads after
        // the hook thread still shows the notice; report success only once.
        if trusted && last_reported == Some(true) {
            return;
        }
        last_reported = Some(trusted);
        let _ = app.emit("cat-input-status", InputStatus { trusted });
    };

    loop {
        if !accessibility::is_trusted() {
            if !prompted {
                prompted = true;
                // The system prompts must be triggered on the main thread.
                let prompt_app = app.clone();
                let _ = prompt_app.run_on_main_thread(accessibility::prompt);
            }
            report(false);
            std::thread::sleep(Duration::from_secs(3));
            continue;
        }

        report(true);
        let result = CGEventTap::with_enabled(
            CGEventTapLocation::HID,
            CGEventTapPlacement::HeadInsertEventTap,
            CGEventTapOptions::ListenOnly,
            events.clone(),
            |_proxy, etype, event| {
                forward_macos_event(etype, event);
                CallbackResult::Keep
            },
            || {
                CFRunLoop::run_current();
            },
        );

        match result {
            Ok(()) => break,
            Err(()) => {
                report(false);
                std::thread::sleep(Duration::from_secs(3));
            }
        }
    }
}
