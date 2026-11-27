// Physical-key mapping (US QWERTY scan codes) shared by the native hook and the
// on-screen mini keyboard. Codes match the DOM KeyboardEvent.code vocabulary.

#[cfg(windows)]
pub fn code_for(vk: u32, scan: u32, extended: bool) -> &'static str {
    if vk == 0x13 {
        return "Pause";
    }
    match scan {
        0x01 => "Escape",
        0x02 => "Digit1",
        0x03 => "Digit2",
        0x04 => "Digit3",
        0x05 => "Digit4",
        0x06 => "Digit5",
        0x07 => "Digit6",
        0x08 => "Digit7",
        0x09 => "Digit8",
        0x0A => "Digit9",
        0x0B => "Digit0",
        0x0C => "Minus",
        0x0D => "Equal",
        0x0E => "Backspace",
        0x0F => "Tab",
        0x10 => "KeyQ",
        0x11 => "KeyW",
        0x12 => "KeyE",
        0x13 => "KeyR",
        0x14 => "KeyT",
        0x15 => "KeyY",
        0x16 => "KeyU",
        0x17 => "KeyI",
        0x18 => "KeyO",
        0x19 => "KeyP",
        0x1A => "BracketLeft",
        0x1B => "BracketRight",
        0x1C => {
            if extended {
                "NumpadEnter"
            } else {
                "Enter"
            }
        }
        0x1D => {
            if extended {
                "ControlRight"
            } else {
                "ControlLeft"
            }
        }
        0x1E => "KeyA",
        0x1F => "KeyS",
        0x20 => "KeyD",
        0x21 => "KeyF",
        0x22 => "KeyG",
        0x23 => "KeyH",
        0x24 => "KeyJ",
        0x25 => "KeyK",
        0x26 => "KeyL",
        0x27 => "Semicolon",
        0x28 => "Quote",
        0x29 => "Backquote",
        0x2A => "ShiftLeft",
        0x2B => "Backslash",
        0x2C => "KeyZ",
        0x2D => "KeyX",
        0x2E => "KeyC",
        0x2F => "KeyV",
        0x30 => "KeyB",
        0x31 => "KeyN",
        0x32 => "KeyM",
        0x33 => "Comma",
        0x34 => "Period",
        0x35 => {
            if extended {
                "NumpadDivide"
            } else {
                "Slash"
            }
        }
        0x36 => "ShiftRight",
        0x37 => {
            if extended {
                "PrintScreen"
            } else {
                "NumpadMultiply"
            }
        }
        0x38 => {
            if extended {
                "AltRight"
            } else {
                "AltLeft"
            }
        }
        0x39 => "Space",
        0x3A => "CapsLock",
        0x3B => "F1",
        0x3C => "F2",
        0x3D => "F3",
        0x3E => "F4",
        0x3F => "F5",
        0x40 => "F6",
        0x41 => "F7",
        0x42 => "F8",
        0x43 => "F9",
        0x44 => "F10",
        0x45 => "NumLock",
        0x46 => "ScrollLock",
        0x47 => {
            if extended {
                "Home"
            } else {
                "Numpad7"
            }
        }
        0x48 => {
            if extended {
                "ArrowUp"
            } else {
                "Numpad8"
            }
        }
        0x49 => {
            if extended {
                "PageUp"
            } else {
                "Numpad9"
            }
        }
        0x4A => "NumpadSubtract",
        0x4B => {
            if extended {
                "ArrowLeft"
            } else {
                "Numpad4"
            }
        }
        0x4C => "Numpad5",
        0x4D => {
            if extended {
                "ArrowRight"
            } else {
                "Numpad6"
            }
        }
        0x4E => "NumpadAdd",
        0x4F => {
            if extended {
                "End"
            } else {
                "Numpad1"
            }
        }
        0x50 => {
            if extended {
                "ArrowDown"
            } else {
                "Numpad2"
            }
        }
        0x51 => {
            if extended {
                "PageDown"
            } else {
                "Numpad3"
            }
        }
        0x52 => {
            if extended {
                "Insert"
            } else {
                "Numpad0"
            }
        }
        0x53 => {
            if extended {
                "Delete"
            } else {
                "NumpadDecimal"
            }
        }
        0x57 => "F11",
        0x58 => "F12",
        0x5B => "MetaLeft",
        0x5C => "MetaRight",
        0x5D => "ContextMenu",
        _ => vk_fallback(vk),
    }
}

#[cfg(windows)]
fn vk_fallback(vk: u32) -> &'static str {
    match vk {
        0x08 => "Backspace",
        0x09 => "Tab",
        0x0D => "Enter",
        0x10 => "ShiftLeft",
        0x11 => "ControlLeft",
        0x12 => "AltLeft",
        0x1B => "Escape",
        0x20 => "Space",
        0x25 => "ArrowLeft",
        0x26 => "ArrowUp",
        0x27 => "ArrowRight",
        0x28 => "ArrowDown",
        0x2C => "PrintScreen",
        0x2E => "Delete",
        0x30 => "Digit0",
        0x31 => "Digit1",
        0x32 => "Digit2",
        0x33 => "Digit3",
        0x34 => "Digit4",
        0x35 => "Digit5",
        0x36 => "Digit6",
        0x37 => "Digit7",
        0x38 => "Digit8",
        0x39 => "Digit9",
        0x41 => "KeyA",
        0x42 => "KeyB",
        0x43 => "KeyC",
        0x44 => "KeyD",
        0x45 => "KeyE",
        0x46 => "KeyF",
        0x47 => "KeyG",
        0x48 => "KeyH",
        0x49 => "KeyI",
        0x4A => "KeyJ",
        0x4B => "KeyK",
        0x4C => "KeyL",
        0x4D => "KeyM",
        0x4E => "KeyN",
        0x4F => "KeyO",
        0x50 => "KeyP",
        0x51 => "KeyQ",
        0x52 => "KeyR",
        0x53 => "KeyS",
        0x54 => "KeyT",
        0x55 => "KeyU",
        0x56 => "KeyV",
        0x57 => "KeyW",
        0x58 => "KeyX",
        0x59 => "KeyY",
        0x5A => "KeyZ",
        0x5B => "MetaLeft",
        0x5C => "MetaRight",
        0x5D => "ContextMenu",
        0x70 => "F1",
        0x71 => "F2",
        0x72 => "F3",
        0x73 => "F4",
        0x74 => "F5",
        0x75 => "F6",
        0x76 => "F7",
        0x77 => "F8",
        0x78 => "F9",
        0x79 => "F10",
        0x7A => "F11",
        0x7B => "F12",
        0x90 => "NumLock",
        0xA0 => "ShiftLeft",
        0xA1 => "ShiftRight",
        0xA2 => "ControlLeft",
        0xA3 => "ControlRight",
        0xA4 => "AltLeft",
        0xA5 => "AltRight",
        _ => "Unidentified",
    }
}

pub fn is_left_hand(code: &str) -> bool {
    matches!(
        code,
        "Escape"
            | "F1"
            | "F2"
            | "F3"
            | "F4"
            | "F5"
            | "Backquote"
            | "Digit1"
            | "Digit2"
            | "Digit3"
            | "Digit4"
            | "Digit5"
            | "Tab"
            | "KeyQ"
            | "KeyW"
            | "KeyE"
            | "KeyR"
            | "KeyT"
            | "CapsLock"
            | "KeyA"
            | "KeyS"
            | "KeyD"
            | "KeyF"
            | "KeyG"
            | "ShiftLeft"
            | "KeyZ"
            | "KeyX"
            | "KeyC"
            | "KeyV"
            | "KeyB"
            | "ControlLeft"
            | "AltLeft"
            | "MetaLeft"
    )
}