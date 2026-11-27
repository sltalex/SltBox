#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.iter().any(|argument| argument == "--mcp") {
        seven_paws_lib::run_mcp_stdio();
        return;
    }
    #[cfg(windows)]
    if let Some(index) = args.iter().position(|argument| argument == "--wait-pid") {
        if let Some(process_id) = args
            .get(index + 1)
            .and_then(|value| value.parse::<u32>().ok())
        {
            seven_paws_lib::wait_for_process_exit(process_id);
            seven_paws_lib::run_after_restart();
            return;
        }
    }
    seven_paws_lib::run()
}
