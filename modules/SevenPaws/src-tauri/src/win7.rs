#![cfg(all(windows, feature = "win7"))]

use crate::{config::save_settings, AppState};
use std::cell::RefCell;
use std::io::Write;
use std::sync::atomic::{AtomicBool, AtomicIsize, AtomicUsize, Ordering};
use std::sync::{Mutex, OnceLock};
use tauri::{AppHandle, Emitter, Manager, WebviewWindow};
use windows_sys::Win32::Foundation::{
    GetLastError, HWND, LPARAM, LRESULT, POINT, RECT, SIZE, WPARAM,
};
use windows_sys::Win32::Graphics::Gdi::{
    CreateCompatibleDC, CreateDIBSection, DeleteDC, DeleteObject, GetDC, GetMonitorInfoW,
    MonitorFromWindow, ReleaseDC, SelectObject, AC_SRC_ALPHA, AC_SRC_OVER, BITMAPINFO,
    BITMAPINFOHEADER, BI_RGB, BLENDFUNCTION, DIB_RGB_COLORS, HBITMAP, HDC, HGDIOBJ, MONITORINFO,
    MONITOR_DEFAULTTONEAREST, RGBQUAD,
};
use windows_sys::Win32::System::LibraryLoader::GetModuleHandleW;
use windows_sys::Win32::UI::Input::KeyboardAndMouse::{ReleaseCapture, SetCapture};
use windows_sys::Win32::UI::WindowsAndMessaging::{
    CreateWindowExW, DefWindowProcW, DestroyWindow, DispatchMessageW, GetClientRect, GetMessageW,
    GetWindowLongPtrW, GetWindowRect, LoadCursorW, PostMessageW, PostQuitMessage, RegisterClassExW,
    SendMessageW, SetCursor, SetWindowLongPtrW, SetWindowPos, ShowWindow, TranslateMessage,
    UpdateLayeredWindow, GWL_EXSTYLE, HTCAPTION, HTCLIENT, HWND_NOTOPMOST, HWND_TOPMOST, IDC_ARROW,
    IDC_HAND, MA_ACTIVATE, MA_NOACTIVATE, MSG, SWP_FRAMECHANGED, SWP_NOACTIVATE, SWP_NOMOVE,
    SWP_NOSIZE, SWP_NOZORDER, SW_HIDE, SW_SHOWNOACTIVATE, ULW_ALPHA, WM_APP, WM_CLOSE, WM_DESTROY,
    WM_ENTERSIZEMOVE, WM_EXITSIZEMOVE, WM_LBUTTONDOWN, WM_LBUTTONUP, WM_MOUSEACTIVATE,
    WM_MOUSEMOVE, WM_NCLBUTTONDOWN, WM_RBUTTONDOWN, WM_RBUTTONUP, WM_SETCURSOR, WNDCLASSEXW,
    WS_EX_LAYERED, WS_EX_TOOLWINDOW, WS_EX_TOPMOST, WS_EX_TRANSPARENT, WS_POPUP,
};

const WM_APP_PRESENT: u32 = WM_APP + 1;
const WM_APP_SET_VISIBLE: u32 = WM_APP + 2;
const WM_APP_SET_POS: u32 = WM_APP + 3;
const WM_APP_SET_SIZE: u32 = WM_APP + 4;
const WM_APP_SET_TOPMOST: u32 = WM_APP + 5;
const WM_APP_SET_CLICK_THROUGH: u32 = WM_APP + 6;
const WM_APP_BEGIN_DRAG: u32 = WM_APP + 7;
const WM_APP_SHUTDOWN: u32 = WM_APP + 8;
const WM_APP_REASSERT_TOPMOST: u32 = WM_APP + 9;

struct PendingFrame {
    width: u32,
    height: u32,
    bgra: Vec<u8>,
    has_frame: bool,
}

struct Hub {
    hwnd: AtomicIsize,
    visible: AtomicBool,
    always_on_top: AtomicBool,
    click_through: AtomicBool,
    over_head: AtomicBool,
    present_queued: AtomicBool,
    pending: Mutex<PendingFrame>,
    app: Mutex<Option<AppHandle>>,
}

static HUB: OnceLock<Hub> = OnceLock::new();
static SUBMITTED_FRAMES: AtomicUsize = AtomicUsize::new(0);
static PRESENTED_FRAMES: AtomicUsize = AtomicUsize::new(0);
static PRESENT_ERRORS: AtomicUsize = AtomicUsize::new(0);

pub fn debug_log(message: impl AsRef<str>) {
    if std::env::var_os("SEVENPAWS_WIN7_DEBUG").as_deref() != Some(std::ffi::OsStr::new("1")) {
        return;
    }
    let Ok(exe) = std::env::current_exe() else {
        return;
    };
    let Some(dir) = exe.parent() else {
        return;
    };
    let Ok(mut file) = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(dir.join("win7-diagnostic.log"))
    else {
        return;
    };
    let _ = writeln!(file, "{}", message.as_ref());
}

fn hub() -> &'static Hub {
    HUB.get_or_init(|| Hub {
        hwnd: AtomicIsize::new(0),
        visible: AtomicBool::new(true),
        always_on_top: AtomicBool::new(true),
        click_through: AtomicBool::new(false),
        over_head: AtomicBool::new(false),
        present_queued: AtomicBool::new(false),
        pending: Mutex::new(PendingFrame {
            width: 0,
            height: 0,
            bgra: Vec::new(),
            has_frame: false,
        }),
        app: Mutex::new(None),
    })
}

pub fn rgba_to_premultiplied_bgra(rgba: &[u8], out_bgra: &mut [u8]) {
    debug_assert_eq!(rgba.len(), out_bgra.len());
    debug_assert_eq!(rgba.len() % 4, 0);
    for (src, dst) in rgba.chunks_exact(4).zip(out_bgra.chunks_exact_mut(4)) {
        let alpha = src[3];
        if alpha == 0 {
            dst.fill(0);
        } else if alpha == 255 {
            dst[0] = src[2];
            dst[1] = src[1];
            dst[2] = src[0];
            dst[3] = 255;
        } else {
            let alpha = alpha as u32;
            dst[0] = ((src[2] as u32 * alpha + 127) / 255) as u8;
            dst[1] = ((src[1] as u32 * alpha + 127) / 255) as u8;
            dst[2] = ((src[0] as u32 * alpha + 127) / 255) as u8;
            dst[3] = alpha as u8;
        }
    }
}

struct DibPresenter {
    memory_dc: HDC,
    bitmap: HBITMAP,
    old_bitmap: HGDIOBJ,
    bits: *mut u8,
    width: u32,
    height: u32,
}

impl DibPresenter {
    fn new() -> Self {
        Self {
            memory_dc: std::ptr::null_mut(),
            bitmap: std::ptr::null_mut(),
            old_bitmap: std::ptr::null_mut(),
            bits: std::ptr::null_mut(),
            width: 0,
            height: 0,
        }
    }

    fn release(&mut self) {
        unsafe {
            if !self.memory_dc.is_null() {
                if !self.old_bitmap.is_null() {
                    SelectObject(self.memory_dc, self.old_bitmap);
                }
                if !self.bitmap.is_null() {
                    DeleteObject(self.bitmap as _);
                }
                DeleteDC(self.memory_dc);
            } else if !self.bitmap.is_null() {
                DeleteObject(self.bitmap as _);
            }
        }
        self.memory_dc = std::ptr::null_mut();
        self.bitmap = std::ptr::null_mut();
        self.old_bitmap = std::ptr::null_mut();
        self.bits = std::ptr::null_mut();
        self.width = 0;
        self.height = 0;
    }

    fn ensure_size(&mut self, width: u32, height: u32) -> bool {
        if width == 0 || height == 0 || width > 4096 || height > 4096 {
            return false;
        }
        if self.width == width
            && self.height == height
            && !self.memory_dc.is_null()
            && !self.bitmap.is_null()
            && !self.bits.is_null()
        {
            return true;
        }
        self.release();
        unsafe {
            let screen_dc = GetDC(std::ptr::null_mut());
            if screen_dc.is_null() {
                return false;
            }
            let memory_dc = CreateCompatibleDC(screen_dc);
            ReleaseDC(std::ptr::null_mut(), screen_dc);
            if memory_dc.is_null() {
                return false;
            }
            let info = BITMAPINFO {
                bmiHeader: BITMAPINFOHEADER {
                    biSize: std::mem::size_of::<BITMAPINFOHEADER>() as u32,
                    biWidth: width as i32,
                    biHeight: -(height as i32),
                    biPlanes: 1,
                    biBitCount: 32,
                    biCompression: BI_RGB,
                    biSizeImage: 0,
                    biXPelsPerMeter: 0,
                    biYPelsPerMeter: 0,
                    biClrUsed: 0,
                    biClrImportant: 0,
                },
                bmiColors: [RGBQUAD {
                    rgbBlue: 0,
                    rgbGreen: 0,
                    rgbRed: 0,
                    rgbReserved: 0,
                }],
            };
            let mut raw_bits: *mut std::ffi::c_void = std::ptr::null_mut();
            let bitmap = CreateDIBSection(
                memory_dc,
                &info,
                DIB_RGB_COLORS,
                &mut raw_bits,
                std::ptr::null_mut(),
                0,
            );
            if bitmap.is_null() || raw_bits.is_null() {
                if !bitmap.is_null() {
                    DeleteObject(bitmap as _);
                }
                DeleteDC(memory_dc);
                return false;
            }
            self.memory_dc = memory_dc;
            self.bitmap = bitmap;
            self.old_bitmap = SelectObject(memory_dc, bitmap as _);
            self.bits = raw_bits as *mut u8;
            self.width = width;
            self.height = height;
            true
        }
    }

    fn present(&mut self, hwnd: HWND, width: u32, height: u32, bgra: &[u8]) -> bool {
        let Some(expected) = (width as usize)
            .checked_mul(height as usize)
            .and_then(|pixels| pixels.checked_mul(4))
        else {
            return false;
        };
        if bgra.len() != expected || !self.ensure_size(width, height) {
            return false;
        }
        unsafe {
            std::ptr::copy_nonoverlapping(bgra.as_ptr(), self.bits, expected);
            let screen_dc = GetDC(std::ptr::null_mut());
            if screen_dc.is_null() {
                return false;
            }
            let source = POINT { x: 0, y: 0 };
            let size = SIZE {
                cx: width as i32,
                cy: height as i32,
            };
            let blend = BLENDFUNCTION {
                BlendOp: AC_SRC_OVER as u8,
                BlendFlags: 0,
                SourceConstantAlpha: 255,
                AlphaFormat: AC_SRC_ALPHA as u8,
            };
            let ok = UpdateLayeredWindow(
                hwnd,
                screen_dc,
                std::ptr::null(),
                &size,
                self.memory_dc,
                &source,
                0,
                &blend,
                ULW_ALPHA,
            );
            let error = if ok == 0 { Some(GetLastError()) } else { None };
            ReleaseDC(std::ptr::null_mut(), screen_dc);
            if let Some(error) = error {
                let errors = PRESENT_ERRORS.fetch_add(1, Ordering::Relaxed) + 1;
                if errors <= 3 || errors % 60 == 0 {
                    debug_log(format!("present:error winerror={error}"));
                }
            }
            ok != 0
        }
    }
}

impl Drop for DibPresenter {
    fn drop(&mut self) {
        self.release();
    }
}

thread_local! {
    static PRESENTER: RefCell<DibPresenter> = RefCell::new(DibPresenter::new());
}

fn hwnd() -> Option<HWND> {
    let raw = hub().hwnd.load(Ordering::Acquire);
    (raw != 0).then_some(raw as HWND)
}

fn present_latest(hwnd: HWND) {
    hub().present_queued.store(false, Ordering::Release);
    if !hub().visible.load(Ordering::Relaxed) {
        return;
    }
    let pending = hub().pending.lock().unwrap();
    if !pending.has_frame || pending.bgra.is_empty() {
        return;
    }
    let ok = PRESENTER.with(|presenter| {
        presenter
            .borrow_mut()
            .present(hwnd, pending.width, pending.height, &pending.bgra)
    });
    if ok {
        let count = PRESENTED_FRAMES.fetch_add(1, Ordering::Relaxed) + 1;
        if count <= 3 || count % 60 == 0 {
            debug_log(format!(
                "present:ok count={count} size={}x{}",
                pending.width, pending.height
            ));
        }
    }
}

fn persist_position(hwnd: HWND) {
    let mut rect = RECT {
        left: 0,
        top: 0,
        right: 0,
        bottom: 0,
    };
    unsafe {
        if GetWindowRect(hwnd, &mut rect) == 0 {
            return;
        }
    }
    let Some(app) = hub().app.lock().unwrap().clone() else {
        return;
    };
    let state = app.state::<AppState>();
    let mut settings = state.settings.lock().unwrap();
    settings.x = Some(rect.left);
    settings.y = Some(rect.top);
    save_settings(&settings);
}

fn emit_pointer(app: &AppHandle, kind: &str, x: i32, y: i32, button: i32) {
    let mut rect = RECT {
        left: 0,
        top: 0,
        right: 0,
        bottom: 0,
    };
    unsafe {
        if GetClientRect(hwnd().unwrap_or(std::ptr::null_mut()), &mut rect) == 0 {
            return;
        }
    }
    let width = (rect.right - rect.left).max(1) as f64;
    let height = (rect.bottom - rect.top).max(1) as f64;
    let _ = app.emit(
        "win7-pointer",
        serde_json::json!({
            "kind": kind,
            "x": (x - rect.left) as f64 / width,
            "y": (y - rect.top) as f64 / height,
            "button": button,
        }),
    );
}

fn set_dragging(value: bool) {
    let Some(app) = hub().app.lock().unwrap().clone() else {
        return;
    };
    app.state::<AppState>()
        .dragging
        .store(value, Ordering::Relaxed);
}

unsafe extern "system" fn window_proc(
    hwnd: HWND,
    message: u32,
    wparam: WPARAM,
    lparam: LPARAM,
) -> LRESULT {
    match message {
        WM_APP_PRESENT => {
            present_latest(hwnd);
            0
        }
        WM_APP_SET_VISIBLE => {
            let visible = wparam != 0;
            hub().visible.store(visible, Ordering::Relaxed);
            ShowWindow(hwnd, if visible { SW_SHOWNOACTIVATE } else { SW_HIDE });
            if visible {
                present_latest(hwnd);
            }
            0
        }
        WM_APP_SET_POS => {
            SetWindowPos(
                hwnd,
                std::ptr::null_mut(),
                wparam as i32,
                lparam as i32,
                0,
                0,
                SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE,
            );
            0
        }
        WM_APP_SET_SIZE => {
            SetWindowPos(
                hwnd,
                std::ptr::null_mut(),
                0,
                0,
                wparam as i32,
                lparam as i32,
                SWP_NOMOVE | SWP_NOZORDER | SWP_NOACTIVATE,
            );
            0
        }
        WM_APP_SET_TOPMOST => {
            let on_top = wparam != 0;
            hub().always_on_top.store(on_top, Ordering::Relaxed);
            SetWindowPos(
                hwnd,
                if on_top { HWND_TOPMOST } else { HWND_NOTOPMOST },
                0,
                0,
                0,
                0,
                SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE,
            );
            0
        }
        WM_APP_SET_CLICK_THROUGH => {
            let ignore = wparam != 0;
            hub().click_through.store(ignore, Ordering::Relaxed);
            let style = GetWindowLongPtrW(hwnd, GWL_EXSTYLE);
            let next = if ignore {
                style | WS_EX_LAYERED as isize | WS_EX_TRANSPARENT as isize
            } else {
                (style | WS_EX_LAYERED as isize) & !(WS_EX_TRANSPARENT as isize)
            };
            if next != style {
                SetWindowLongPtrW(hwnd, GWL_EXSTYLE, next);
                SetWindowPos(
                    hwnd,
                    std::ptr::null_mut(),
                    0,
                    0,
                    0,
                    0,
                    SWP_NOMOVE | SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE | SWP_FRAMECHANGED,
                );
            }
            0
        }
        WM_APP_BEGIN_DRAG => {
            set_dragging(true);
            ReleaseCapture();
            SendMessageW(hwnd, WM_NCLBUTTONDOWN, HTCAPTION as WPARAM, 0);
            set_dragging(false);
            persist_position(hwnd);
            0
        }
        WM_APP_REASSERT_TOPMOST => {
            if hub().visible.load(Ordering::Relaxed) && hub().always_on_top.load(Ordering::Relaxed)
            {
                SetWindowPos(
                    hwnd,
                    HWND_TOPMOST,
                    0,
                    0,
                    0,
                    0,
                    SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE,
                );
            }
            0
        }
        WM_APP_SHUTDOWN => {
            DestroyWindow(hwnd);
            0
        }
        WM_MOUSEACTIVATE => {
            let mouse_message = ((lparam >> 16) & 0xffff) as u32;
            if mouse_message == WM_RBUTTONDOWN {
                MA_ACTIVATE as LRESULT
            } else {
                MA_NOACTIVATE as LRESULT
            }
        }
        WM_SETCURSOR => {
            if (lparam & 0xffff) == HTCLIENT as isize {
                let cursor = if hub().over_head.load(Ordering::Relaxed)
                    && !hub().click_through.load(Ordering::Relaxed)
                {
                    IDC_HAND
                } else {
                    IDC_ARROW
                };
                let handle = LoadCursorW(std::ptr::null_mut(), cursor);
                if !handle.is_null() {
                    SetCursor(handle);
                    return 1;
                }
            }
            DefWindowProcW(hwnd, message, wparam, lparam)
        }
        WM_MOUSEMOVE => {
            if let Some(app) = hub().app.lock().unwrap().as_ref() {
                let x = (lparam & 0xffff) as i16 as i32;
                let y = ((lparam >> 16) & 0xffff) as i16 as i32;
                emit_pointer(app, "move", x, y, 0);
            }
            0
        }
        WM_LBUTTONDOWN => {
            SetCapture(hwnd);
            if let Some(app) = hub().app.lock().unwrap().as_ref() {
                let x = (lparam & 0xffff) as i16 as i32;
                let y = ((lparam >> 16) & 0xffff) as i16 as i32;
                emit_pointer(app, "down", x, y, 0);
            }
            0
        }
        WM_LBUTTONUP => {
            ReleaseCapture();
            if let Some(app) = hub().app.lock().unwrap().as_ref() {
                let x = (lparam & 0xffff) as i16 as i32;
                let y = ((lparam >> 16) & 0xffff) as i16 as i32;
                emit_pointer(app, "up", x, y, 0);
            }
            0
        }
        WM_RBUTTONDOWN => 0,
        WM_RBUTTONUP => {
            if let Some(app) = hub().app.lock().unwrap().clone() {
                crate::show_settings_window(&app);
            }
            0
        }
        WM_ENTERSIZEMOVE => {
            set_dragging(true);
            0
        }
        WM_EXITSIZEMOVE => {
            set_dragging(false);
            persist_position(hwnd);
            0
        }
        WM_CLOSE => {
            DestroyWindow(hwnd);
            0
        }
        WM_DESTROY => {
            PostQuitMessage(0);
            0
        }
        _ => DefWindowProcW(hwnd, message, wparam, lparam),
    }
}

pub fn init(app: AppHandle, x: i32, y: i32, width: u32, height: u32, always_on_top: bool) -> bool {
    debug_log(format!(
        "init:start pos={x},{y} size={width}x{height} top={always_on_top}"
    ));
    *hub().app.lock().unwrap() = Some(app);
    hub().always_on_top.store(always_on_top, Ordering::Relaxed);
    if hwnd().is_some() {
        set_position(x, y);
        set_size(width, height);
        set_always_on_top(always_on_top);
        return true;
    }

    let (ready_tx, ready_rx) = std::sync::mpsc::channel();
    let spawn = std::thread::Builder::new()
        .name("7paws-win7-layered".to_string())
        .spawn(move || {
            let class_name: Vec<u16> = "SevenPawsWin7LayeredClass\0".encode_utf16().collect();
            let title: Vec<u16> = "7paws\0".encode_utf16().collect();
            unsafe {
                let instance = GetModuleHandleW(std::ptr::null());
                let class = WNDCLASSEXW {
                    cbSize: std::mem::size_of::<WNDCLASSEXW>() as u32,
                    style: 0,
                    lpfnWndProc: Some(window_proc),
                    cbClsExtra: 0,
                    cbWndExtra: 0,
                    hInstance: instance,
                    hIcon: std::ptr::null_mut(),
                    hCursor: LoadCursorW(std::ptr::null_mut(), IDC_ARROW),
                    hbrBackground: std::ptr::null_mut(),
                    lpszMenuName: std::ptr::null(),
                    lpszClassName: class_name.as_ptr(),
                    hIconSm: std::ptr::null_mut(),
                };
                let class_result = RegisterClassExW(&class);
                debug_log(format!("init:register_class={class_result}"));
                let mut style = WS_EX_LAYERED | WS_EX_TOOLWINDOW;
                if always_on_top {
                    style |= WS_EX_TOPMOST;
                }
                let window = CreateWindowExW(
                    style,
                    class_name.as_ptr(),
                    title.as_ptr(),
                    WS_POPUP,
                    x,
                    y,
                    width.max(1) as i32,
                    height.max(1) as i32,
                    std::ptr::null_mut(),
                    std::ptr::null_mut(),
                    instance,
                    std::ptr::null(),
                );
                if window.is_null() {
                    debug_log(format!("init:create_window_error={}", GetLastError()));
                    let _ = ready_tx.send(false);
                    return;
                }
                hub().hwnd.store(window as isize, Ordering::Release);
                debug_log("init:window_created");
                ShowWindow(window, SW_SHOWNOACTIVATE);
                let _ = ready_tx.send(true);
                let mut message: MSG = std::mem::zeroed();
                while GetMessageW(&mut message, std::ptr::null_mut(), 0, 0) > 0 {
                    TranslateMessage(&message);
                    DispatchMessageW(&message);
                }
            }
        });
    if spawn.is_err() {
        return false;
    }
    ready_rx
        .recv_timeout(std::time::Duration::from_secs(3))
        .unwrap_or(false)
}

pub fn submit_rgba_frame(width: u32, height: u32, rgba: &[u8]) {
    let Some(expected) = (width as usize)
        .checked_mul(height as usize)
        .and_then(|pixels| pixels.checked_mul(4))
    else {
        return;
    };
    if width == 0 || height == 0 || width > 4096 || height > 4096 || rgba.len() != expected {
        debug_log(format!(
            "submit:invalid size={width}x{height} bytes={}",
            rgba.len()
        ));
        return;
    }
    let count = SUBMITTED_FRAMES.fetch_add(1, Ordering::Relaxed) + 1;
    if count <= 3 || count % 60 == 0 {
        let mut visible = 0usize;
        let mut alpha_sum = 0u64;
        let mut alpha_max = 0u8;
        for pixel in rgba.chunks_exact(4) {
            let alpha = pixel[3];
            if alpha > 0 {
                visible += 1;
            }
            alpha_sum += alpha as u64;
            alpha_max = alpha_max.max(alpha);
        }
        debug_log(format!(
            "submit:ok count={count} size={width}x{height} visible={visible} alpha_avg={:.2} alpha_max={alpha_max}",
            alpha_sum as f64 / (width as f64 * height as f64)
        ));
    }
    {
        let mut pending = hub().pending.lock().unwrap();
        if pending.bgra.len() != expected {
            pending.bgra.resize(expected, 0);
        }
        rgba_to_premultiplied_bgra(rgba, &mut pending.bgra);
        pending.width = width;
        pending.height = height;
        pending.has_frame = true;
    }
    if let Some(window) = hwnd() {
        if !hub().present_queued.swap(true, Ordering::AcqRel) {
            unsafe {
                PostMessageW(window, WM_APP_PRESENT, 0, 0);
            }
        }
    }
}

pub fn get_rect() -> Option<(i32, i32, i32, i32)> {
    let window = hwnd()?;
    let mut rect = RECT {
        left: 0,
        top: 0,
        right: 0,
        bottom: 0,
    };
    unsafe {
        if GetWindowRect(window, &mut rect) == 0 {
            return None;
        }
    }
    Some((
        rect.left,
        rect.top,
        (rect.right - rect.left).max(1),
        (rect.bottom - rect.top).max(1),
    ))
}

pub fn is_visible() -> bool {
    hwnd().is_some_and(|window| unsafe {
        windows_sys::Win32::UI::WindowsAndMessaging::IsWindowVisible(window) != 0
    })
}

pub fn set_visible(visible: bool) {
    hub().visible.store(visible, Ordering::Relaxed);
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_SET_VISIBLE, visible as WPARAM, 0);
        }
    }
}

pub fn set_position(x: i32, y: i32) {
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_SET_POS, x as isize as usize, y as isize);
        }
    }
}

pub fn set_size(width: u32, height: u32) {
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(
                window,
                WM_APP_SET_SIZE,
                width.max(1) as isize as usize,
                height.max(1) as isize,
            );
        }
    }
}

pub fn set_always_on_top(on_top: bool) {
    hub().always_on_top.store(on_top, Ordering::Relaxed);
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_SET_TOPMOST, on_top as WPARAM, 0);
        }
    }
}

pub fn presenter_hwnd() -> Option<HWND> {
    hwnd()
}

pub fn reassert_topmost() {
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_REASSERT_TOPMOST, 0, 0);
        }
    }
}

pub fn set_click_through(ignore: bool) {
    hub().click_through.store(ignore, Ordering::Relaxed);
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_SET_CLICK_THROUGH, ignore as WPARAM, 0);
        }
    }
}

pub fn set_over_head(over: bool) {
    hub().over_head.store(over, Ordering::Relaxed);
}

pub fn force_tool_window(win: &WebviewWindow) {
    let Ok(raw) = win.hwnd() else {
        return;
    };
    let window = raw.0 as HWND;
    unsafe {
        let style = GetWindowLongPtrW(window, GWL_EXSTYLE);
        if style & WS_EX_TOOLWINDOW as isize == 0 {
            SetWindowLongPtrW(window, GWL_EXSTYLE, style | WS_EX_TOOLWINDOW as isize);
            SetWindowPos(
                window,
                std::ptr::null_mut(),
                0,
                0,
                0,
                0,
                SWP_NOMOVE | SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE | SWP_FRAMECHANGED,
            );
        }
        ShowWindow(window, SW_HIDE);
        ShowWindow(window, SW_SHOWNOACTIVATE);
    }
}

pub fn set_window_position(win: &WebviewWindow, x: i32, y: i32) {
    let Ok(raw) = win.hwnd() else {
        return;
    };
    let window = raw.0 as HWND;
    unsafe {
        SetWindowPos(
            window,
            std::ptr::null_mut(),
            x,
            y,
            0,
            0,
            SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE,
        );
        let mut rect = RECT {
            left: 0,
            top: 0,
            right: 0,
            bottom: 0,
        };
        if GetWindowRect(window, &mut rect) != 0 {
            let mut monitor_info = MONITORINFO {
                cbSize: std::mem::size_of::<MONITORINFO>() as u32,
                rcMonitor: RECT {
                    left: 0,
                    top: 0,
                    right: 0,
                    bottom: 0,
                },
                rcWork: RECT {
                    left: 0,
                    top: 0,
                    right: 0,
                    bottom: 0,
                },
                dwFlags: 0,
            };
            let monitor = MonitorFromWindow(window, MONITOR_DEFAULTTONEAREST);
            if GetMonitorInfoW(monitor, &mut monitor_info) != 0 {
                let width = rect.right - rect.left;
                let height = rect.bottom - rect.top;
                let max_x = (monitor_info.rcWork.right - width).max(monitor_info.rcWork.left);
                let max_y = (monitor_info.rcWork.bottom - height).max(monitor_info.rcWork.top);
                let clamped_x = rect.left.clamp(monitor_info.rcWork.left, max_x);
                let clamped_y = rect.top.clamp(monitor_info.rcWork.top, max_y);
                if clamped_x != rect.left || clamped_y != rect.top {
                    SetWindowPos(
                        window,
                        std::ptr::null_mut(),
                        clamped_x,
                        clamped_y,
                        0,
                        0,
                        SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE,
                    );
                    GetWindowRect(window, &mut rect);
                }
            }
            debug_log(format!(
                "settings:move target={x},{y} actual={},{} size={}x{}",
                rect.left,
                rect.top,
                rect.right - rect.left,
                rect.bottom - rect.top
            ));
        }
    }
}

pub fn begin_drag() {
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_BEGIN_DRAG, 0, 0);
        }
    }
}

pub fn shutdown() {
    if let Some(window) = hwnd() {
        unsafe {
            PostMessageW(window, WM_APP_SHUTDOWN, 0, 0);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::rgba_to_premultiplied_bgra;

    #[test]
    fn converts_straight_rgba_to_premultiplied_bgra() {
        let rgba = [
            255, 128, 64, 0, 20, 140, 240, 255, 200, 100, 50, 128, 30, 30, 30, 26,
        ];
        let mut bgra = [0; 16];
        rgba_to_premultiplied_bgra(&rgba, &mut bgra);
        assert_eq!(&bgra[..4], &[0, 0, 0, 0]);
        assert_eq!(&bgra[4..8], &[240, 140, 20, 255]);
        assert_eq!(&bgra[8..12], &[25, 50, 100, 128]);
        assert_eq!(&bgra[12..], &[3, 3, 3, 26]);
    }
}
