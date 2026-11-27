use objc2_app_kit::{NSWindow, NSWindowCollectionBehavior};
use tauri::WebviewWindow;

pub fn configure_spaces(win: &WebviewWindow) -> tauri::Result<()> {
    let native_win = win.clone();
    win.run_on_main_thread(move || {
        let Ok(handle) = native_win.ns_window() else {
            return;
        };
        // SAFETY: the live window handle is resolved and used on the AppKit thread.
        let Some(window) = (unsafe { handle.cast::<NSWindow>().as_ref() }) else {
            return;
        };
        let mut behavior = window.collectionBehavior();
        behavior.remove(
            NSWindowCollectionBehavior::MoveToActiveSpace
                | NSWindowCollectionBehavior::FullScreenPrimary
                | NSWindowCollectionBehavior::FullScreenNone,
        );
        behavior.insert(
            NSWindowCollectionBehavior::CanJoinAllSpaces
                | NSWindowCollectionBehavior::FullScreenAuxiliary,
        );
        window.setCollectionBehavior(behavior);
    })
}
