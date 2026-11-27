# DeskBox integration

Account play: right-click the desktop cat and choose **猫咪互动**. Sign in through Box first. Browse other accounts' cats or search by account, pet name, or pet code. No friendship or acceptance is required: gifts (3 fish), ten-minute visits and 25-minute focus sessions start directly. Both participants receive 10 fish when the server deadline completes; ending early gives no reward. Either participant can end a session. Shared wave/high-five actions transmit predefined animation events only, never keyboard content.

Account saves live in `seven-paws/accounts/<hashed-account-id>`. The first signed-in account copies the old local cat without removing the original. Other accounts start separately. Only coat/custom cats and pet name sync; desktop coordinates, scale, privacy, and key-label preferences remain local. A revision conflict selects the cloud appearance and retains a local outfit backup. Box holds authentication; the native renderer reads a bounded file bridge without receiving bearer tokens. Offline state removes guest cats and disables social actions.

The social panel also exposes account-owned growth and inventory. A fish meal costs 20 account coins and grants 25 hunger, 5 mood, and 10 XP; a permanent wool ball costs 1500 account coins and grants 12 mood/5 XP per play with a ten-minute server cooldown. Every 50 XP adds a level. Hunger/mood decay by 5/2 per hour, including offline time, without losing levels. Completed focus grants another 15 XP to each participant. A bell nameplate (2000 account coins) and stars (3000 account coins) can be equipped on desktop cats, including visitors. Presence and recent interaction toasts are driven by authenticated server snapshots; no input text is sent. The additional server dependency is `api/_app/pet_care.php`; apply `database/060_seven_paws_care.sql` before updating `pets.php`. Production migration, file readback, and authenticated two-account tests passed on 2026-11-27 (evidence `.codex-test-temp/pet-social-20261127172913/`).

The shop spends the existing Box account wallet (`aquarium_profiles`) shared with aquarium and tree decorations. It displays the current account coin balance and requires an initialized wallet. Requests must explicitly declare `currency: account_coins`; old clients receive an update message instead of silently changing currency. Debit, inventory grant, and the shared `aquarium_wallet_entries` ledger entry commit in one transaction. Namespaced operation hashes in that ledger prevent charging an old retry even after the seven-day pet operation cache expires. Fish remain separate for gifts and companionship rewards. No new currency balance or database migration is introduced.

Managed desktop windows reserve an additional 84 CSS pixels above the original-size pet canvas for social notices. Hit regions are converted from canvas to window coordinates so the transparent notice area does not intercept clicks. Guest cats use the same layout; the legacy Win7 capture path retains its original sizing.

Dedicated social poses now include a food bowl, moving yarn ball, waving, high-five and celebration spin. Local input takes priority, including anonymous privacy taps. Hunger <=25 triggers idle food searching; mood <=30 triggers loafing; mood >=80 permits occasional joyful hops. Automatic behavior respects idle-action and reduced-motion settings. Level 2 unlocks celebration, level 3 a free companion title, level 5 a free heart effect; server transactions grant each account its missing rewards once, including existing high-level cats. New visits/focus require a target heartbeat within 30 seconds, while gifts still work offline. Production validation: `.codex-test-temp/pet-social-20261127200010/deployment.json`.

The upstream package does not include the generator sources referenced by `public/vendor/7paws.js`; DeskBox maintains its renderer pose extension in that distributed source. Preserve those additions when updating upstream. High-five raises paws toward the visitor's side; it does not implement physical collision or reposition the pair to touch.

Server setup requires `database/059_seven_paws_social.sql`, `api/_app/pets.php`, and `api/v1/pets/social.php`. Run the migration once before enabling the authenticated endpoint. Local validation: `php server/deskbox-api/tests/pets_service_test.php`. Production deployment and migration completed on 2026-11-27 after the user freed server capacity. File readback and live two-account MySQL tests passed, temporary validation accounts/helper were removed, and the running Box reconnected successfully. Evidence: `.codex-test-temp/pet-social-20261127163708/deployment.json`.

Upstream: https://github.com/7wdn/7paws (MIT, version 0.4.2).
The downloaded upstream tree is recorded in `UPSTREAM.json`; `LICENSE` retains the original notice.

Enable **7paws 小猫** in **设置 → 功能格子**. DeskBox starts the bundled original Tauri desktop pet window: transparent, borderless, always on top by default and absent from the taskbar. Right-click the pet for its settings; drag outside its head to reposition it. The pet is not hosted in a DeskBox widget.

The original app keeps coats, custom cats, local photo color extraction, keyboard layouts, petting, idle/sleep animations, global input and reminders. Native settings and cats persist under `%LOCALAPPDATA%\DeskBox\seven-paws\desktop`. A development data root relocates that directory with the rest of the data. The previous embedded profile remains under `seven-paws\webview2` for recovery.

The original global input hooks drive the pet. New managed settings follow individual keys and show the original floating key labels. Privacy mode is off by default, matching upstream; enabling it in pet settings replaces key labels with anonymous paw taps. Transparent areas pass clicks through to underlying desktop applications.

DeskBox owns the process lifecycle: disabling the feature stops the pet, and closing or crashing DeskBox ends its child process. Separate pet autostart, administrator mode, updater and MCP settings are disabled in managed mode. The original tray is optional and off by default.

Rebuild web assets from the repository root:

```powershell
.\scripts\build-seven-paws.ps1 -UseMirror
```

The script builds the frontend in a temporary path because Vite cannot resolve this repository's `#` path reliably, then embeds it into `7paws.exe` with Cargo's `tauri/custom-protocol` feature. `-UseMirror` selects the rsproxy Cargo registry for this command only. The executable and license/provenance files go into `src\DeskBox\Assets\SevenPaws`; DeskBox copies them to build and publish output. Rebuild DeskBox after changing these assets.
