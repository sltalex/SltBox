use crate::config::app_data_dir;
use crate::{AppState, BASE_HEIGHT, BASE_WIDTH, BROWSER_ARGS, social_headroom};
use serde_json::Value;
use tauri::{AppHandle, Manager};
use std::time::{Duration, SystemTime};

fn snapshot() -> Value {
    let path = app_data_dir().join("social.json");
    let fresh = std::fs::metadata(&path).and_then(|m| m.modified()).ok()
        .and_then(|t| SystemTime::now().duration_since(t).ok()).map(|d| d.as_secs() < 20).unwrap_or(false);
    if !fresh { return serde_json::json!({"connected":false,"message":"正在等待 Box 同步…"}); }
    std::fs::read(path).ok().and_then(|s| serde_json::from_slice(&s).ok())
        .unwrap_or_else(|| serde_json::json!({"connected":false,"message":"正在同步…"}))
}

#[tauri::command]
pub fn get_social() -> Value { snapshot() }

#[tauri::command]
pub fn submit_social(command: Value) -> Result<(), String> {
    if std::env::var_os("DESKBOX_SEVENPAWS_HOME").is_none() { return Err("请从 Box 启动小猫。".into()); }
    let id = command["operation_id"].as_str().ok_or("操作编号无效。")?;
    if id.len()!=32 || !id.bytes().all(|c| c.is_ascii_hexdigit()) { return Err("操作编号无效。".into()); }
    if !snapshot()["connected"].as_bool().unwrap_or(false) { return Err("请先登录并等待服务连接。".into()); }
    let data = serde_json::to_vec(&command).map_err(|e|e.to_string())?;
    if data.len()>32768 { return Err("请求过大。".into()); }
    let dir = app_data_dir().join("social-commands");
    std::fs::create_dir_all(&dir).map_err(|e|e.to_string())?;
    if std::fs::read_dir(&dir).map_err(|e|e.to_string())?.count()>=32 { return Err("请等待之前的操作完成。".into()); }
    let temp=dir.join(format!("{id}.tmp"));
    std::fs::write(&temp,data).map_err(|e|e.to_string())?;
    std::fs::rename(temp,dir.join(format!("{id}.json"))).map_err(|e|e.to_string())
}

#[tauri::command]
pub async fn open_social(app: AppHandle) -> Result<(),String> {
    if let Some(win)=app.get_webview_window("social") { let _=win.show(); let _=win.set_focus(); return Ok(()); }
    tauri::WebviewWindowBuilder::new(&app,"social",tauri::WebviewUrl::App("social.html".into()))
        .title("Box · 猫咪互动").inner_size(620.0,680.0).min_inner_size(440.0,460.0)
        .additional_browser_args(BROWSER_ARGS).build().map_err(|e|e.to_string())?;
    Ok(())
}

pub fn start(app: AppHandle) {
    if std::env::var_os("DESKBOX_SEVENPAWS_HOME").is_none() { return; }
    std::thread::spawn(move || {
        let mut active = String::new();
        loop {
            std::thread::sleep(Duration::from_secs(1));
            let data=snapshot();
            let me=data["me"]["user_id"].as_str().unwrap_or("");
            let guest=data["sessions"].as_array().and_then(|rows|rows.iter().find(|s|
                s["kind"]=="visit" && s["status"]=="active" && s["recipient"]==me));
            let id=if data["connected"]==true { guest.and_then(|s|s["id"].as_str()).unwrap_or("") } else { "" };
            if id!=active {
                if let Some(win)=app.get_webview_window("guest") { let _=win.destroy(); }
                active.clear();
                if !id.is_empty() {
                    let s=app.state::<AppState>().settings.lock().unwrap().clone();
                    let built=tauri::WebviewWindowBuilder::new(&app,"guest",tauri::WebviewUrl::App("index.html?guest=1".into()))
                        .title("来串门的小猫").inner_size(BASE_WIDTH*s.scale,BASE_HEIGHT*s.scale + social_headroom())
                        .decorations(false).transparent(true).shadow(false).resizable(false)
                        .always_on_top(true).skip_taskbar(true).additional_browser_args(BROWSER_ARGS).visible(false).build();
                    if let Ok(win)=built {
                        let _=win.set_ignore_cursor_events(true);
                        let _=win.show();
                        active=id.to_string();
                    }
                }
            }
            if let (Some(guest_win),Some(main))=(app.get_webview_window("guest"),app.get_webview_window("main")) {
                if let (Ok(pos),Ok(size))=(main.outer_position(),main.outer_size()) {
                    let x=pos.x-size.width as i32;
                    let _=guest_win.set_position(tauri::PhysicalPosition::new(x.max(0),pos.y));
                }
            }
        }
    });
}
