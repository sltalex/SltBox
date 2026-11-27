use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;

pub const MIN_SCALE: f64 = 0.1;
pub const MAX_SCALE: f64 = 1.5;

pub(crate) const COATS: &[&str] = &[
    "tuxedo",
    "orange",
    "tabby",
    "calico",
    "siamese",
    "black",
    "white",
    "blue",
    "gray-white",
    "orange-white",
    "tortie",
    "cream",
    "silver",
];

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct AppSettings {
    pub x: Option<i32>,
    pub y: Option<i32>,
    pub scale: f64,
    pub opacity: f64,
    pub always_on_top: bool,
    pub click_through_locked: bool,
    pub purr_pet: bool,
    pub purr_sleep: bool,
    pub autostart: bool,
    pub run_as_admin: bool,
    pub privacy_mode: bool,
    pub left_mouse: bool,
    pub coat: String,
    pub sleep_minutes: u32,
    pub idle_actions: bool,
    pub show_key_labels: bool,
    pub keyboard_layout: String,
    pub show_tray_icon: bool,
    pub mcp_enabled: bool,
    pub reminder_enabled: bool,
    pub reminder_text: String,
    pub reminder_interval_minutes: u32,
}

impl Default for AppSettings {
    fn default() -> Self {
        Self {
            x: None,
            y: None,
            scale: 0.6,
            opacity: 1.0,
            always_on_top: true,
            click_through_locked: false,
            purr_pet: true,
            purr_sleep: false,
            autostart: false,
            run_as_admin: false,
            privacy_mode: false,
            left_mouse: false,
            coat: "tuxedo".to_string(),
            sleep_minutes: 5,
            idle_actions: true,
            show_key_labels: true,
            keyboard_layout: "65".to_string(),
            show_tray_icon: true,
            mcp_enabled: true,
            reminder_enabled: false,
            reminder_text: String::new(),
            reminder_interval_minutes: 30,
        }
    }
}

fn is_slug(value: &str) -> bool {
    (1..=32).contains(&value.len())
        && !value.starts_with('-')
        && value
            .chars()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '-' || c == '_')
}

pub(crate) fn is_hex_color(value: &str) -> bool {
    value.len() == 7 && value.starts_with('#') && value[1..].chars().all(|c| c.is_ascii_hexdigit())
}

impl AppSettings {
    pub fn normalize(&mut self) {
        self.scale = self.scale.clamp(MIN_SCALE, MAX_SCALE);
        self.opacity = self.opacity.clamp(0.1, 1.0);
        self.sleep_minutes = self.sleep_minutes.min(120);
        self.reminder_interval_minutes = self.reminder_interval_minutes.clamp(1, 1440);
        self.reminder_text = self.reminder_text.chars().take(120).collect();
        // Custom coat ids are validated against cats.json by the app; here only the shape is checked.
        if !COATS.contains(&self.coat.as_str()) && !is_slug(&self.coat) {
            self.coat = "tuxedo".to_string();
        }
        if self.keyboard_layout != "104" {
            self.keyboard_layout = "65".to_string();
        }
    }
}

fn data_dir(name: &str) -> PathBuf {
    #[cfg(windows)]
    {
        let base = std::env::var("APPDATA")
            .map(PathBuf::from)
            .unwrap_or_else(|_| PathBuf::from("."));
        base.join(name)
    }
    #[cfg(target_os = "macos")]
    {
        let home = std::env::var("HOME")
            .map(PathBuf::from)
            .unwrap_or_else(|_| PathBuf::from("."));
        home.join("Library").join("Application Support").join(name)
    }
    #[cfg(all(not(windows), not(target_os = "macos")))]
    {
        let home = std::env::var("HOME")
            .map(PathBuf::from)
            .unwrap_or_else(|_| PathBuf::from("."));
        home.join(".config").join(name)
    }
}

pub fn app_data_dir() -> PathBuf {
    if let Some(root) = std::env::var_os("DESKBOX_SEVENPAWS_HOME") {
        return PathBuf::from(root);
    }
    data_dir("7paws")
}

pub fn config_path() -> PathBuf {
    app_data_dir().join("settings.json")
}

fn legacy_config_path() -> PathBuf {
    data_dir("cat-pet").join("settings.json")
}

pub fn try_load_settings() -> Option<AppSettings> {
    let content = fs::read_to_string(config_path()).ok()?;
    let mut parsed = serde_json::from_str::<AppSettings>(&content).ok()?;
    parsed.normalize();
    Some(parsed)
}

pub fn load_settings() -> AppSettings {
    if let Some(parsed) = try_load_settings() {
        return parsed;
    }
    // Migrate settings written before the 7paws rename.
    if let Ok(content) = fs::read_to_string(legacy_config_path()) {
        if let Ok(mut parsed) = serde_json::from_str::<AppSettings>(&content) {
            parsed.normalize();
            save_settings(&parsed);
            return parsed;
        }
    }
    AppSettings::default()
}

pub fn save_settings(settings: &AppSettings) {
    let path = config_path();
    if let Some(parent) = path.parent() {
        let _ = fs::create_dir_all(parent);
    }
    if let Ok(json) = serde_json::to_string_pretty(settings) {
        let _ = fs::write(path, json);
    }
}

#[cfg(windows)]
pub fn set_autostart(enabled: bool) -> Result<(), String> {
    if std::env::var_os("DESKBOX_SEVENPAWS_HOME").is_some() { return Ok(()); }
    use std::ffi::OsStr;
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::System::Registry::{
        RegCloseKey, RegDeleteValueW, RegOpenKeyExW, RegSetValueExW, HKEY, HKEY_CURRENT_USER,
        KEY_SET_VALUE, REG_SZ,
    };

    fn to_wide(s: &str) -> Vec<u16> {
        OsStr::new(s)
            .encode_wide()
            .chain(std::iter::once(0))
            .collect()
    }

    let subkey = to_wide("Software\\Microsoft\\Windows\\CurrentVersion\\Run");
    let value_name = to_wide("7paws");
    let legacy_value_name = to_wide("CatPet");

    unsafe {
        let mut hkey: HKEY = std::ptr::null_mut();
        let status = RegOpenKeyExW(
            HKEY_CURRENT_USER,
            subkey.as_ptr(),
            0,
            KEY_SET_VALUE,
            &mut hkey,
        );
        if status != 0 {
            return Err(format!("RegOpenKeyExW failed with code {}", status));
        }

        let result = if enabled {
            let exe = std::env::current_exe()
                .map_err(|e| e.to_string())?
                .to_string_lossy()
                .to_string();
            let data = to_wide(&format!("\"{}\"", exe));
            let byte_len = (data.len() * std::mem::size_of::<u16>()) as u32;
            let st = RegSetValueExW(
                hkey,
                value_name.as_ptr(),
                0,
                REG_SZ,
                data.as_ptr() as *const u8,
                byte_len,
            );
            if st == 0 {
                let _ = RegDeleteValueW(hkey, legacy_value_name.as_ptr());
                Ok(())
            } else {
                Err(format!("RegSetValueExW failed with code {}", st))
            }
        } else {
            let _ = RegDeleteValueW(hkey, value_name.as_ptr());
            let _ = RegDeleteValueW(hkey, legacy_value_name.as_ptr());
            Ok(())
        };

        RegCloseKey(hkey);
        result
    }
}

#[cfg(windows)]
pub fn is_portable_install() -> bool {
    std::env::current_exe()
        .ok()
        .and_then(|path| path.parent().map(|parent| parent.to_path_buf()))
        .map(|dir| !dir.join("uninstall.exe").exists())
        .unwrap_or(true)
}

#[cfg(not(windows))]
pub fn is_portable_install() -> bool {
    false
}

#[cfg(windows)]
pub fn create_desktop_shortcut() -> Result<String, String> {
    use std::os::windows::process::CommandExt;
    use std::process::Command;

    const CREATE_NO_WINDOW: u32 = 0x0800_0000;
    let exe = std::env::current_exe().map_err(|e| e.to_string())?;
    let exe = exe.to_string_lossy().replace('\'', "''");
    let work_dir = std::env::current_exe()
        .ok()
        .and_then(|path| path.parent().map(|parent| parent.to_path_buf()))
        .map(|path| path.to_string_lossy().replace('\'', "''"))
        .unwrap_or_default();
    let script = format!(
        "$ErrorActionPreference = 'Stop'; $desktop = [Environment]::GetFolderPath('Desktop'); $lnk = Join-Path $desktop '7paws.lnk'; $ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut($lnk); $s.TargetPath = '{exe}'; $s.WorkingDirectory = '{work_dir}'; $s.IconLocation = '{exe},0'; $s.Description = '7paws 桌面小猫'; $s.Save(); if (-not (Test-Path $lnk)) {{ throw 'Shortcut file was not created' }}",
        exe = exe,
        work_dir = work_dir,
    );
    let output = Command::new("powershell.exe")
        .args([
            "-NoProfile",
            "-NonInteractive",
            "-WindowStyle",
            "Hidden",
            "-Command",
            &script,
        ])
        .creation_flags(CREATE_NO_WINDOW)
        .output()
        .map_err(|e| format!("无法启动 PowerShell：{e}"))?;
    if output.status.success() {
        Ok("桌面快捷方式已创建".to_string())
    } else {
        let error = String::from_utf8_lossy(&output.stderr).trim().to_string();
        Err(if error.is_empty() {
            "创建桌面快捷方式失败".to_string()
        } else {
            format!("创建桌面快捷方式失败：{error}")
        })
    }
}

#[cfg(not(windows))]
pub fn create_desktop_shortcut() -> Result<String, String> {
    Err("只有 Windows 支持创建桌面快捷方式".to_string())
}

#[cfg(target_os = "macos")]
const LAUNCH_AGENT_LABEL: &str = "com.qoder.7paws";

#[cfg(target_os = "macos")]
fn launch_agent_path() -> Result<PathBuf, String> {
    let home = std::env::var("HOME").map_err(|e| e.to_string())?;
    Ok(PathBuf::from(home)
        .join("Library")
        .join("LaunchAgents")
        .join(format!("{LAUNCH_AGENT_LABEL}.plist")))
}

#[cfg(target_os = "macos")]
fn xml_escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&apos;")
}

#[cfg(target_os = "macos")]
pub fn set_autostart(enabled: bool) -> Result<(), String> {
    let path = launch_agent_path()?;
    if !enabled {
        return match fs::remove_file(&path) {
            Ok(()) => Ok(()),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(e) => Err(e.to_string()),
        };
    }

    if let Some(dir) = path.parent() {
        fs::create_dir_all(dir).map_err(|e| e.to_string())?;
    }

    let exe = std::env::current_exe().map_err(|e| e.to_string())?;
    let bundle = exe
        .ancestors()
        .find(|p| p.extension().and_then(|e| e.to_str()) == Some("app"));

    let mut arguments = String::new();
    if let Some(bundle) = bundle {
        // Launch through LaunchServices so the bundled app starts as a whole.
        arguments.push_str("    <string>/usr/bin/open</string>\n");
        arguments.push_str("    <string>-a</string>\n");
        arguments.push_str(&format!(
            "    <string>{}</string>\n",
            xml_escape(bundle.to_string_lossy().as_ref())
        ));
    } else {
        arguments.push_str(&format!(
            "    <string>{}</string>\n",
            xml_escape(exe.to_string_lossy().as_ref())
        ));
    }

    let plist = format!(
        r#"<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>{LAUNCH_AGENT_LABEL}</string>
  <key>ProgramArguments</key>
  <array>
{arguments}  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <false/>
</dict>
</plist>
"#
    );
    fs::write(&path, plist).map_err(|e| e.to_string())
}

#[cfg(not(any(windows, target_os = "macos")))]
pub fn set_autostart(_enabled: bool) -> Result<(), String> {
    Ok(())
}
