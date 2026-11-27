import { readFileSync } from "node:fs";

const file = process.argv[2];
if (!file) throw new Error("usage: node scripts/verify-win7-pe.mjs <7paws.exe>");

const data = readFileSync(file);
const u16 = (offset) => data.readUInt16LE(offset);
const u32 = (offset) => data.readUInt32LE(offset);
const pe = u32(0x3c);
if (data.toString("ascii", pe, pe + 4) !== "PE\0\0") {
  throw new Error("not a PE executable");
}

const machine = u16(pe + 4);
const sectionCount = u16(pe + 6);
const optionalSize = u16(pe + 20);
const optional = pe + 24;
const magic = u16(optional);
const subsystem = u16(optional + 68);
const subsystemMajor = u16(optional + 48);
const subsystemMinor = u16(optional + 50);
const directoryOffset = optional + (magic === 0x20b ? 112 : 96);
const importRva = u32(directoryOffset + 8);
const delayImportRva = u32(directoryOffset + 13 * 8);
const sectionTable = optional + optionalSize;
const sections = [];

for (let index = 0; index < sectionCount; index++) {
  const offset = sectionTable + index * 40;
  sections.push({
    virtualSize: u32(offset + 8),
    virtualAddress: u32(offset + 12),
    rawSize: u32(offset + 16),
    rawOffset: u32(offset + 20),
  });
}

function rvaToOffset(rva) {
  const section = sections.find((entry) =>
    rva >= entry.virtualAddress &&
    rva < entry.virtualAddress + Math.max(entry.virtualSize, entry.rawSize),
  );
  if (!section) return null;
  const delta = rva - section.virtualAddress;
  if (delta >= section.rawSize) return null;
  return section.rawOffset + delta;
}

function asciiAt(rva) {
  const offset = rvaToOffset(rva);
  if (offset === null) return "";
  let end = offset;
  while (end < data.length && data[end] !== 0) end++;
  return data.toString("ascii", offset, end);
}

function importsAt(rva) {
  const names = [];
  let descriptor = rvaToOffset(rva);
  if (descriptor === null) return names;
  while (descriptor + 20 <= data.length) {
    const nameRva = u32(descriptor + 12);
    if (nameRva === 0) break;
    names.push(asciiAt(nameRva).toLowerCase());
    descriptor += 20;
  }
  return names;
}

const imports = [...importsAt(importRva), ...importsAt(delayImportRva)];
const failures = [];
if (machine !== 0x8664) failures.push(`expected x64 PE machine, got 0x${machine.toString(16)}`);
if (subsystem !== 2) failures.push(`expected Windows GUI subsystem, got ${subsystem}`);
if (subsystemMajor !== 6 || subsystemMinor !== 1) {
  failures.push(`expected subsystem 6.1, got ${subsystemMajor}.${subsystemMinor}`);
}
if (!imports.includes("msvcrt.dll")) failures.push("missing system msvcrt.dll import");
if (!imports.includes("ole32.dll")) failures.push("missing ole32.dll import");
if (imports.includes("combase.dll")) failures.push("unexpected combase.dll import");
const unsupported = imports.filter((name) => name.startsWith("api-ms-win-crt-"));
if (unsupported.length) failures.push(`unexpected UCRT API-set imports: ${unsupported.join(", ")}`);

if (failures.length) throw new Error(failures.join("\n"));
console.log(JSON.stringify({ file, machine: "0x8664", subsystem: "6.1", imports }, null, 2));
