use crate::config::{app_data_dir, is_hex_color, COATS};
use serde::{Deserialize, Serialize};
use std::collections::HashSet;
use std::fs;
use std::path::PathBuf;

pub const MAX_SPOTS: usize = 24;
const PATTERNS: [&str; 4] = ["solid", "bicolor", "tabby", "calico"];

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct CatSpot {
    /// "head" | "body"; x/y are texture uv in 0..=1 (u: 0.25 faces the viewer, 0.75 is the back).
    pub part: String,
    pub x: f64,
    pub y: f64,
    pub rx: f64,
    pub ry: f64,
    /// Degrees.
    pub rotation: f64,
    pub color: String,
    pub opacity: f64,
}

impl Default for CatSpot {
    fn default() -> Self {
        Self {
            part: "body".to_string(),
            x: 0.5,
            y: 0.5,
            rx: 0.08,
            ry: 0.08,
            rotation: 0.0,
            color: "#ffffff".to_string(),
            opacity: 1.0,
        }
    }
}

impl CatSpot {
    fn normalize(&mut self) {
        if self.part != "head" {
            self.part = "body".to_string();
        }
        self.x = self.x.clamp(0.0, 1.0);
        self.y = self.y.clamp(0.0, 1.0);
        self.rx = self.rx.clamp(0.005, 0.6);
        self.ry = self.ry.clamp(0.005, 0.6);
        self.rotation = self.rotation.rem_euclid(360.0);
        if !is_hex_color(&self.color) {
            self.color = "#ffffff".to_string();
        }
        self.opacity = self.opacity.clamp(0.0, 1.0);
    }
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct CustomCat {
    pub id: String,
    pub name: String,
    pub fur: String,
    pub patch: String,
    pub accent: String,
    pub eye: String,
    /// Second iris colour for heterochromia; older cats.json files omit it.
    #[serde(default)]
    pub eye2: String,
    pub pattern: String,
    pub spots: Vec<CatSpot>,
}

impl Default for CustomCat {
    fn default() -> Self {
        Self {
            id: String::new(),
            name: "我的猫".to_string(),
            fur: "#151515".to_string(),
            patch: "#f8f6ef".to_string(),
            accent: "#e9973f".to_string(),
            eye: "#e2b93b".to_string(),
            eye2: "#e2b93b".to_string(),
            pattern: "bicolor".to_string(),
            spots: Vec::new(),
        }
    }
}

pub fn sanitize_cat_id(raw: &str) -> String {
    let cleaned: String = raw
        .trim()
        .to_lowercase()
        .chars()
        .map(|c| {
            if c.is_ascii_alphanumeric() || c == '-' || c == '_' {
                c
            } else {
                '-'
            }
        })
        .collect();
    let trimmed = cleaned.trim_matches('-').to_string();
    let limited: String = trimmed.chars().take(32).collect();
    if limited.is_empty() {
        "my-cat".to_string()
    } else {
        limited
    }
}

impl CustomCat {
    pub fn normalize(&mut self) {
        self.id = sanitize_cat_id(&self.id);
        let fallback = CustomCat::default();
        let name = self.name.trim();
        self.name = if name.is_empty() {
            fallback.name.clone()
        } else {
            name.chars().take(16).collect()
        };
        if !is_hex_color(&self.fur) {
            self.fur = fallback.fur;
        }
        if !is_hex_color(&self.patch) {
            self.patch = fallback.patch;
        }
        if !is_hex_color(&self.accent) {
            self.accent = fallback.accent;
        }
        if !is_hex_color(&self.eye) {
            self.eye = fallback.eye;
        }
        if !is_hex_color(&self.eye2) {
            self.eye2 = self.eye.clone();
        }
        if !PATTERNS.contains(&self.pattern.as_str()) {
            self.pattern = fallback.pattern;
        }
        self.spots.truncate(MAX_SPOTS);
        for spot in &mut self.spots {
            spot.normalize();
        }
    }
}

pub fn is_builtin_coat(id: &str) -> bool {
    COATS.contains(&id)
}

pub fn cats_path() -> PathBuf {
    app_data_dir().join("cats.json")
}

pub fn load_custom_cats() -> Vec<CustomCat> {
    try_load_custom_cats().unwrap_or_default()
}

/// None means the file exists but cannot be parsed; callers should keep their current state.
pub fn try_load_custom_cats() -> Option<Vec<CustomCat>> {
    let path = cats_path();
    if !path.exists() {
        return Some(Vec::new());
    }
    let content = fs::read_to_string(&path).ok()?;
    let parsed = serde_json::from_str::<Vec<CustomCat>>(&content).ok()?;
    let mut seen = HashSet::new();
    let mut cats = Vec::new();
    for mut cat in parsed {
        cat.normalize();
        if is_builtin_coat(&cat.id) || !seen.insert(cat.id.clone()) {
            continue;
        }
        cats.push(cat);
    }
    Some(cats)
}

pub fn save_custom_cats(cats: &[CustomCat]) {
    let path = cats_path();
    if let Some(parent) = path.parent() {
        let _ = fs::create_dir_all(parent);
    }
    if let Ok(json) = serde_json::to_string_pretty(cats) {
        let _ = fs::write(path, json);
    }
}

pub fn unique_cat_id(base: &str, cats: &[CustomCat]) -> String {
    let base = sanitize_cat_id(base);
    let free = |candidate: &str| -> bool {
        !is_builtin_coat(candidate) && !cats.iter().any(|c| c.id == candidate)
    };
    if free(&base) {
        return base;
    }
    for n in 2..1000 {
        let candidate = format!("{base}-{n}");
        if free(&candidate) {
            return candidate;
        }
    }
    "my-cat".to_string()
}

pub fn upsert_custom_cat(cats: &mut Vec<CustomCat>, mut cat: CustomCat) -> CustomCat {
    cat.normalize();
    if is_builtin_coat(&cat.id) {
        cat.id = unique_cat_id(&format!("{}-custom", cat.id), cats);
    }
    if let Some(existing) = cats.iter_mut().find(|c| c.id == cat.id) {
        *existing = cat.clone();
    } else {
        cats.push(cat.clone());
    }
    save_custom_cats(cats);
    cat
}

pub fn delete_custom_cat(cats: &mut Vec<CustomCat>, id: &str) -> bool {
    let before = cats.len();
    cats.retain(|c| c.id != id);
    if cats.len() == before {
        return false;
    }
    save_custom_cats(cats);
    true
}