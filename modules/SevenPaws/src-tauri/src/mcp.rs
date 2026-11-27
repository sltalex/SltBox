use crate::cats::{self, CustomCat};
use crate::config;
use serde_json::{json, Value};
use std::io::{BufRead, Write};

const SERVER_NAME: &str = "7paws";

const BUILTIN: &[(&str, &str)] = &[
    ("tuxedo", "奶牛猫"),
    ("orange", "橘猫"),
    ("tabby", "狸花猫"),
    ("calico", "三花猫"),
    ("siamese", "暹罗猫"),
    ("black", "黑猫"),
    ("white", "白猫"),
    ("blue", "蓝灰猫"),
    ("gray-white", "灰白猫"),
    ("orange-white", "橘白猫"),
    ("tortie", "玳瑁猫"),
    ("cream", "奶油猫"),
    ("silver", "银渐层"),
];

fn tool_definitions() -> Value {
    json!([
        {
            "name": "list_cats",
            "description": "List built-in and custom 7paws desktop-pet cats and show which one is active.",
            "inputSchema": { "type": "object", "properties": {}, "additionalProperties": false }
        },
        {
            "name": "get_cat_schema",
            "description": "Get the custom cat schema: colour slot meanings, the four patterns, the spot (marking) coordinate system with ready-to-use templates, and a full example cat. Read this before creating a cat.",
            "inputSchema": { "type": "object", "properties": {}, "additionalProperties": false }
        },
        {
            "name": "upsert_cat",
            "description": "Create or replace a named custom cat (fur colours, pattern, unique markings) from a photo or description; the running pet hot-reloads within about a second.",
            "inputSchema": {
                "type": "object",
                "required": ["cat"],
                "properties": {
                    "cat": {
                        "type": "object",
                        "required": ["id", "fur"],
                        "properties": {
                            "id": { "type": "string", "description": "Unique lowercase slug, e.g. 'my-orange-tabby'" },
                            "name": { "type": "string", "description": "Display name, e.g. '我家大橘'" },
                            "fur": { "type": "string", "description": "Main coat hex, e.g. '#e9973f'" },
                            "patch": { "type": "string", "description": "Second colour hex: tuxedo/bicolor patches, tabby belly, calico patch A" },
                            "accent": { "type": "string", "description": "Third colour hex used by calico patches and tail" },
                            "eye": { "type": "string", "description": "Iris hex, e.g. '#5c8a3a'" },
                            "eye2": { "type": "string", "description": "Second iris hex for heterochromia; defaults to eye" },
                            "pattern": { "type": "string", "enum": ["solid", "bicolor", "tabby", "calico"] },
                            "spots": {
                                "type": "array",
                                "maxItems": 24,
                                "description": "Unique markings drawn on the fur texture (see get_cat_schema)",
                                "items": {
                                    "type": "object",
                                    "required": ["part", "x", "y", "rx", "ry", "color"],
                                    "properties": {
                                        "part": { "type": "string", "enum": ["head", "body"] },
                                        "x": { "type": "number", "minimum": 0, "maximum": 1 },
                                        "y": { "type": "number", "minimum": 0, "maximum": 1 },
                                        "rx": { "type": "number", "minimum": 0.005, "maximum": 0.6 },
                                        "ry": { "type": "number", "minimum": 0.005, "maximum": 0.6 },
                                        "rotation": { "type": "number", "description": "Degrees, default 0" },
                                        "color": { "type": "string" },
                                        "opacity": { "type": "number", "minimum": 0, "maximum": 1, "default": 1 }
                                    }
                                }
                            }
                        }
                    },
                    "activate": { "type": "boolean", "default": true, "description": "Switch the live pet to this cat (default: true)" }
                }
            }
        },
        {
            "name": "switch_cat",
            "description": "Switch the active desktop pet to a cat id (built-in or custom).",
            "inputSchema": {
                "type": "object",
                "required": ["id"],
                "properties": { "id": { "type": "string" } }
            }
        },
        {
            "name": "delete_cat",
            "description": "Delete a custom cat by id. Built-in cats cannot be deleted; the pet falls back to tuxedo if the deleted cat was active.",
            "inputSchema": {
                "type": "object",
                "required": ["id"],
                "properties": { "id": { "type": "string" } }
            }
        }
    ])
}

fn cat_schema_guide() -> Value {
    json!({
        "description": "7paws custom cat schema. Pass a cat object to upsert_cat to create or replace a named desktop-pet cat. The pet renders a stylised low-poly model, so colours plus a pattern plus spots are the levers that make it look like the user's own cat.",
        "colours": {
            "fur": "Main coat colour. Outline, whiskers and the eye-closed line are derived from it automatically.",
            "patch": "Second colour. bicolor: patches (tuxedo bib/socks look). tabby: belly/muzzle tone. calico: first patch colour.",
            "accent": "Third colour, only used by calico (second patch colour, also the tail).",
            "eye": "Iris colour.",
            "eye2": "Optional second iris colour (heterochromia); omit or repeat eye for matching eyes."
        },
        "patterns": {
            "solid": "One colour; patch is ignored.",
            "bicolor": "Fur base with patch-coloured markings (tuxedo-like).",
            "tabby": "Fur base with darker stripes derived from fur; patch is the lighter belly tone.",
            "calico": "Fur base with patch and accent blobs on body and head; tail takes accent."
        },
        "spots": {
            "note": "Up to 24 ellipse markings painted onto the body/head fur texture. This is how unique per-cat markings are expressed (paws/legs and tail do not support spots yet).",
            "coordinates": {
                "part": "'head' or 'body'",
                "x": "0..1 texture u. 0.25 faces the viewer (face/chest centre), 0.75 is the back. Values wrap around.",
                "y": "0..1 texture v: 0 bottom, 1 top.",
                "rx": "0.005..0.6 horizontal radius as a fraction of the texture.",
                "ry": "0.005..0.6 vertical radius.",
                "rotation": "degrees",
                "color": "hex colour",
                "opacity": "0..1, default 1"
            },
            "templates": [
                { "part": "head", "x": 0.25, "y": 0.74, "rx": 0.045, "ry": 0.09, "rotation": 0, "color": "#b85d19", "opacity": 0.9, "hint": "forehead stripe / M marking centre" },
                { "part": "head", "x": 0.125, "y": 0.58, "rx": 0.07, "ry": 0.09, "rotation": 0, "color": "#3c3634", "opacity": 1, "hint": "left-eye patch" },
                { "part": "head", "x": 0.375, "y": 0.58, "rx": 0.07, "ry": 0.09, "rotation": 0, "color": "#3c3634", "opacity": 1, "hint": "right-eye patch" },
                { "part": "body", "x": 0.25, "y": 0.42, "rx": 0.11, "ry": 0.16, "rotation": 0, "color": "#f8f6ef", "opacity": 1, "hint": "white chest bib" },
                { "part": "body", "x": 0.75, "y": 0.55, "rx": 0.15, "ry": 0.2, "rotation": 0, "color": "#c26a1f", "opacity": 1, "hint": "back patch" }
            ],
            "tuning": "Coordinates are approximate; ask the user how the markings look and nudge x/y/rx/ry accordingly."
        },
        "example": {
            "id": "my-orange-tabby",
            "name": "我家大橘",
            "fur": "#e9973f",
            "patch": "#fbe8c9",
            "accent": "#c26a1f",
            "eye": "#5c8a3a",
            "pattern": "tabby",
            "spots": [
                { "part": "head", "x": 0.25, "y": 0.74, "rx": 0.045, "ry": 0.09, "rotation": 0, "color": "#b85d19", "opacity": 0.9 },
                { "part": "body", "x": 0.25, "y": 0.42, "rx": 0.11, "ry": 0.16, "rotation": 0, "color": "#f8f6ef", "opacity": 1 }
            ]
        },
        "workflow": "Look at the user's cat photo, pick fur/patch/accent/eye colours, choose the closest pattern, add spots for signature markings, then call upsert_cat with activate: true. If the app is not running the change applies on next launch."
    })
}

fn ok_text(payload: Value) -> Value {
    let text = serde_json::to_string_pretty(&payload).unwrap_or_else(|_| payload.to_string());
    json!({ "content": [{ "type": "text", "text": text }], "isError": false })
}

fn err_text(message: &str) -> Value {
    json!({ "content": [{ "type": "text", "text": message }], "isError": true })
}

fn known_cat_ids(custom: &[CustomCat]) -> Vec<String> {
    let mut ids: Vec<String> = BUILTIN.iter().map(|(id, _)| id.to_string()).collect();
    ids.extend(custom.iter().map(|c| c.id.clone()));
    ids
}

fn execute_tool(name: &str, args: &Value) -> Value {
    match name {
        "list_cats" => {
            let settings = config::load_settings();
            let custom = cats::load_custom_cats();
            let builtin: Vec<Value> = BUILTIN
                .iter()
                .map(|(id, label)| json!({ "id": id, "name": label }))
                .collect();
            ok_text(json!({
                "activeCatId": settings.coat,
                "storagePath": cats::cats_path().to_string_lossy(),
                "builtin": builtin,
                "custom": custom
            }))
        }
        "get_cat_schema" => ok_text(cat_schema_guide()),
        "upsert_cat" => {
            let Some(cat_value) = args.get("cat") else {
                return err_text("Missing required 'cat' object argument.");
            };
            let mut cat: CustomCat = match serde_json::from_value(cat_value.clone()) {
                Ok(cat) => cat,
                Err(e) => return err_text(&format!("Invalid cat JSON: {e}")),
            };
            let mut list = cats::load_custom_cats();
            if cat.id.trim().is_empty() {
                cat.id = cats::unique_cat_id(&cat.name, &list);
            }
            let saved = cats::upsert_custom_cat(&mut list, cat);
            let activate = args
                .get("activate")
                .and_then(|v| v.as_bool())
                .unwrap_or(true);
            if activate {
                let mut settings = config::load_settings();
                settings.coat = saved.id.clone();
                config::save_settings(&settings);
            }
            ok_text(json!({ "status": "saved", "activated": activate, "cat": saved }))
        }
        "switch_cat" => {
            let Some(id) = args.get("id").and_then(|v| v.as_str()) else {
                return err_text("Missing required 'id' string argument.");
            };
            let id = cats::sanitize_cat_id(id);
            let custom = cats::load_custom_cats();
            let ids = known_cat_ids(&custom);
            if !ids.contains(&id) {
                return err_text(&format!("Cat '{id}' not found. Available ids: {ids:?}"));
            }
            let mut settings = config::load_settings();
            settings.coat = id.clone();
            config::save_settings(&settings);
            ok_text(json!({ "status": "switched", "activeCatId": id }))
        }
        "delete_cat" => {
            let Some(id) = args.get("id").and_then(|v| v.as_str()) else {
                return err_text("Missing required 'id' string argument.");
            };
            let id = cats::sanitize_cat_id(id);
            if cats::is_builtin_coat(&id) {
                return err_text(&format!("'{id}' is a built-in cat and cannot be deleted."));
            }
            let mut list = cats::load_custom_cats();
            if !cats::delete_custom_cat(&mut list, &id) {
                return err_text(&format!("No custom cat with id '{id}'."));
            }
            let mut settings = config::load_settings();
            if settings.coat == id {
                settings.coat = "tuxedo".to_string();
                config::save_settings(&settings);
            }
            ok_text(json!({ "status": "deleted", "id": id, "activeCatId": settings.coat }))
        }
        _ => err_text(&format!("Unknown tool: {name}")),
    }
}

fn handle_jsonrpc(request: &Value) -> Option<Value> {
    let id = request.get("id").cloned();
    let method = request.get("method").and_then(|m| m.as_str()).unwrap_or("");
    let params = request.get("params").cloned().unwrap_or_else(|| json!({}));

    // Notifications (no id) must not receive a response.
    if id.is_none() || method.starts_with("notifications/") {
        return None;
    }
    let id = id.unwrap();

    let result = match method {
        "initialize" => {
            let client_ver = params
                .get("protocolVersion")
                .and_then(|v| v.as_str())
                .unwrap_or("2024-11-05");
            let version = match client_ver {
                "2025-06-18" | "2025-03-26" | "2024-11-05" => client_ver,
                _ => "2024-11-05",
            };
            Ok(json!({
                "protocolVersion": version,
                "capabilities": { "tools": { "listChanged": false } },
                "serverInfo": { "name": SERVER_NAME, "version": env!("CARGO_PKG_VERSION") },
                "instructions": "Call get_cat_schema first, then upsert_cat with the colours, pattern and spots of the user's cat (activate: true). The running desktop pet picks the change up within about a second."
            }))
        }
        "ping" => Ok(json!({})),
        "tools/list" => Ok(json!({ "tools": tool_definitions() })),
        "tools/call" => {
            if !config::try_load_settings()
                .map(|s| s.mcp_enabled)
                .unwrap_or(true)
            {
                return Some(json!({
                    "jsonrpc": "2.0",
                    "id": id,
                    "result": err_text("7paws MCP is disabled in the app settings (允许 AI 接入（MCP）). Ask the user to enable it and retry.")
                }));
            }
            let tool = params.get("name").and_then(|v| v.as_str()).unwrap_or("");
            let args = params.get("arguments").cloned().unwrap_or_else(|| json!({}));
            Ok(execute_tool(tool, &args))
        }
        _ => Err((-32601, format!("Method not found: {method}"))),
    };

    Some(match result {
        Ok(res) => json!({ "jsonrpc": "2.0", "id": id, "result": res }),
        Err((code, message)) => json!({
            "jsonrpc": "2.0",
            "id": id,
            "error": { "code": code, "message": message }
        }),
    })
}

/// `7paws.exe --mcp`: a zero-dependency stdio MCP server used by AI clients.
/// It writes cats.json/settings.json directly; the running app watches those
/// files, so edits reach the live pet without restarting anything.
pub fn run_stdio() {
    let stdin = std::io::stdin();
    let stdout = std::io::stdout();
    let mut out = stdout.lock();
    for line in stdin.lock().lines() {
        let Ok(line) = line else { break };
        let line = line.trim();
        if line.is_empty() {
            continue;
        }
        let parsed: Value = match serde_json::from_str(line) {
            Ok(value) => value,
            Err(e) => {
                let _ = writeln!(
                    out,
                    "{}",
                    json!({
                        "jsonrpc": "2.0",
                        "id": Value::Null,
                        "error": { "code": -32700, "message": format!("Parse error: {e}") }
                    })
                );
                let _ = out.flush();
                continue;
            }
        };
        if let Some(response) = handle_jsonrpc(&parsed) {
            let _ = writeln!(out, "{response}");
            let _ = out.flush();
        }
    }
}