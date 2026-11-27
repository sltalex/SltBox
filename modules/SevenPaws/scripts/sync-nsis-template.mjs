#!/usr/bin/env node
// Regenerates src-tauri/nsis/installer.nsi from the NSIS template embedded in
// the installed @tauri-apps/cli, then applies the 7paws-specific changes:
//   1. bottom-left BrandingText shows "<product> <version>", not the copyright
//   2. the installer follows the Windows UI language (English fallback) and
//      stores the selected language for the uninstaller
//   3. the uninstaller falls back to the Windows UI language when no language
//      was stored (installs created before this change)
//   4. elevated app instances get an explicit admin-permission hint before the
//      upstream Restart Manager close prompt
//
// Run: node scripts/sync-nsis-template.mjs

import { mkdir, readFile, readdir, writeFile } from "node:fs/promises";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const OUTPUT = path.join(ROOT, "src-tauri", "nsis", "installer.nsi");

async function findCliModule() {
  const store = path.join(ROOT, "node_modules", ".pnpm");
  const candidates = (await readdir(store)).filter((name) => name.startsWith("@tauri-apps+cli-"));
  for (const candidate of candidates) {
    const inside = path.join(store, candidate, "node_modules", "@tauri-apps");
    for (const packageName of await readdir(inside)) {
      if (!packageName.startsWith("cli-")) {
        continue;
      }
      const directory = path.join(inside, packageName);
      for (const file of await readdir(directory)) {
        if (file.endsWith(".node")) {
          return path.join(directory, file);
        }
      }
    }
  }
  return null;
}

function templateIn(binary) {
  const marker = Buffer.from('Name "${PRODUCTNAME}"', "utf8");
  const at = binary.indexOf(marker);
  if (at === -1) {
    return null;
  }
  let start = at;
  while (start > 0 && binary[start - 1] !== 0) {
    start -= 1;
  }
  let end = at;
  while (end < binary.length && binary[end] !== 0) {
    end += 1;
  }
  return binary.subarray(start, end).toString("utf8");
}

async function cliVersion() {
  const manifest = await readFile(
    path.join(ROOT, "node_modules", "@tauri-apps", "cli", "package.json"),
    "utf8",
  );
  return JSON.parse(manifest).version;
}

function insertAfter(lines, anchor, block) {
  const at = lines.indexOf(anchor);
  if (at === -1) {
    throw new Error(`template anchor not found: ${anchor}`);
  }
  lines.splice(at + 1, 0, ...block);
}

function insertBefore(lines, anchor, block) {
  const at = lines.indexOf(anchor);
  if (at === -1) {
    throw new Error(`template anchor not found: ${anchor}`);
  }
  lines.splice(at, 0, ...block);
}

// The write has to sit after the whole `!ifmacrodef` block: 7paws does not
// define the hook macros, so anything inside the conditional never runs.
function insertAfterBlockEnd(lines, anchor, block) {
  const at = lines.indexOf(anchor);
  if (at === -1) {
    throw new Error(`template anchor not found: ${anchor}`);
  }
  let end = at + 1;
  while (end < lines.length && !lines[end].includes("!endif")) {
    end += 1;
  }
  if (end >= lines.length) {
    throw new Error(`no !endif after template anchor: ${anchor}`);
  }
  lines.splice(end + 1, 0, ...block);
}

function detectLanguage(indent) {
  return [
    "",
    `${indent}; Follow the Windows UI language; English is the template's first language.`,
    `${indent}System::Call 'kernel32::GetUserDefaultUILanguage() i .r0'`,
    `${indent}IntOp $1 $0 & 0x3FF`,
    `${indent}\${If} $1 == 4`,
    `${indent}  StrCpy $LANGUAGE 2052`,
    `${indent}\${EndIf}`,
  ];
}

async function generate() {
  const module = await findCliModule();
  if (module === null) {
    throw new Error("the Tauri CLI's native module is not installed: run `pnpm install`");
  }
  const upstream = templateIn(await readFile(module));
  if (
    upstream === null ||
    !upstream.startsWith("Unicode true") ||
    upstream.split(/\r?\n/).length < 500
  ) {
    throw new Error(
      process.platform === "win32"
        ? "no usable NSIS template found in the Tauri CLI module"
        : "no usable NSIS template found: the NSIS bundler only ships in the Windows CLI build, run this check on Windows",
    );
  }
  const lines = upstream.split(/\r?\n/);

  const branding = lines.indexOf('BrandingText "${COPYRIGHT}"');
  if (branding === -1) {
    throw new Error('the template no longer contains BrandingText "${COPYRIGHT}"');
  }
  lines[branding] = 'BrandingText "${PRODUCTNAME} ${VERSION}"';

  insertAfter(lines, "Function .onInit", detectLanguage("  "));

  insertAfterBlockEnd(
    lines,
    "    !insertmacro NSIS_HOOK_POSTINSTALL",
    [
      "  ; Remember the selected language for the uninstaller.",
      '  WriteRegStr HKCU "${MANUPRODUCTKEY}" "Installer Language" "$LANGUAGE"',
    ],
  );

  insertBefore(lines, "  !insertmacro MUI_UNGETLANGUAGE", [
    ...detectLanguage("  "),
    "",
  ]);

  insertAfter(lines, "{{/each}}", [
    'LangString elevatedAppRunning ${LANG_ENGLISH} "${PRODUCTNAME} may be running as administrator. Please exit it from the tray, or end the process in Task Manager opened as administrator, then try again."',
    'LangString elevatedAppRunning ${LANG_SIMPCHINESE} "检测到 ${PRODUCTNAME} 可能正在以管理员权限运行。请从托盘退出，或以管理员身份打开任务管理器结束进程后重试。"',
    "",
  ]);

  insertBefore(lines, "Section Install", [
    "; Restart Manager reports permission denied when the running app is elevated.",
    "; Explain that case before the upstream generic close prompt.",
    "!macro CheckIfElevatedAppRunning executablePath productName",
    "  !define UniqueID ${__LINE__}",
    '  nsis_tauri_utils::StrReplace "$(elevatedAppRunning)" "{{product_name}}" "${productName}"',
    "  Pop $R1",
    "  !insertmacro RestartManager_StartSession $R0",
    "  ${If} $R0 != \"\"",
    '    !insertmacro RestartManager_RegisterFile $R0 "${executablePath}"',
    "    ${If} $0 = 0",
    '      System::Call \'RSTRTMGR::RmGetList(p R0, *i .r1, *i .r2, p 0, *i .r3) i .r0\'',
    "      ${If} $0 = ${ERROR_MORE_DATA}",
    "        IntOp $0 $3 & ${RmRebootReasonPermissionDenied}",
    "        ${If} $0 <> 0",
    "          !insertmacro RestartManager_EndSession $R0",
    "          IfSilent elevated_silent_${UniqueID} 0",
    "          MessageBox MB_OK|MB_ICONEXCLAMATION $R1",
    "          Abort",
    "          elevated_silent_${UniqueID}:",
    "          DetailPrint $R1",
    "          Abort",
    "        ${EndIf}",
    "      ${EndIf}",
    "    ${EndIf}",
    "    !insertmacro RestartManager_EndSession $R0",
    "  ${EndIf}",
    "  !undef UniqueID",
    "!macroend",
    "",
  ]);

  for (let index = 0; index < lines.length; index++) {
    if (lines[index].includes("!insertmacro CheckIfAppIsRunning")) {
      lines.splice(index, 0, '  !insertmacro CheckIfElevatedAppRunning "$INSTDIR\\${MAINBINARYNAME}.exe" "${PRODUCTNAME}"');
      index++;
    }
  }

  const header = [
    `; Generated by scripts/sync-nsis-template.mjs from the template inside @tauri-apps/cli ${await cliVersion()}.`,
    "; Do not edit; run the script instead. Differences from the upstream template:",
    ";   1. BrandingText shows the product and version instead of the copyright.",
    ";   2. Installer follows the Windows UI language and stores it for the uninstaller.",
    ";   3. Uninstaller falls back to the Windows UI language when nothing is stored.",
    ";   4. Elevated app instances get an explicit admin-permission hint.",
    "",
  ].join("\n");

  return `${header}${lines.join("\n")}`;
}

const content = await generate();

if (process.argv.includes("--check")) {
  const current = await readFile(OUTPUT, "utf8").catch(() => null);
  // Git may check the file out with CRLF (core.autocrlf on Windows); the
  // template itself is LF, so compare with line endings normalised.
  const same = current !== null && current.replace(/\r\n/g, "\n") === content;
  if (same) {
    console.log("NSIS template is up to date");
  } else {
    console.error("NSIS template is out of date; run: node scripts/sync-nsis-template.mjs");
    process.exitCode = 1;
  }
} else {
  await mkdir(path.dirname(OUTPUT), { recursive: true });
  await writeFile(OUTPUT, content);
  console.log(`wrote ${path.relative(ROOT, OUTPUT)}`);
}