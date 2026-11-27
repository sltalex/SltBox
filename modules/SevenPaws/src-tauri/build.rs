fn main() {
    #[cfg(feature = "win7")]
    {
        if std::env::var("CARGO_CFG_TARGET_ARCH").as_deref() != Ok("x86_64") {
            panic!("the win7 feature only supports x86_64-pc-windows-msvc");
        }

        oldwin::inject();
        cc::Build::new()
            .file("win7/combase_ole32_shim.c")
            .opt_level(2)
            .compile("combase_ole32_shim");
        println!("cargo:rustc-link-arg-bins=/SUBSYSTEM:WINDOWS,6.01");
        println!("cargo:rustc-link-arg-bins=/ENTRY:mainCRTStartup");
        let browser_args = "--autoplay-policy=no-user-gesture-required --disable-backgrounding-occluded-windows --disable-renderer-backgrounding --disable-background-timer-throttling --disable-features=CalculateNativeWinOcclusion";
        let config = format!(
            r#"{{"app":{{"windows":[{{"label":"main","visible":false,"additionalBrowserArgs":"{browser_args}"}}]}}}}"#
        );
        std::env::set_var("TAURI_CONFIG", &config);
        println!("cargo:rustc-env=TAURI_CONFIG={config}");
    }

    tauri_build::build()
}
