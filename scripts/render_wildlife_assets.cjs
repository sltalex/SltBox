// Rasterize vector artwork for inspection; PNGs are never application assets.
const fs = require('node:fs');
const path = require('node:path');
const config = JSON.parse(fs.readFileSync(0, 'utf8'));
const sharp = require(require.resolve('sharp', { paths: [config.moduleRoot || process.cwd()] }));

async function main() {
  fs.mkdirSync(config.outputRoot, { recursive: true });
  const jobs = [];
  for (const id of config.ids) {
    for (const size of config.sizes) jobs.push({ id, size, folder: 'Source' });
    if (config.blurred) jobs.push({ id, size: 128, folder: 'Blur' });
  }
  let cursor = 0;
  await Promise.all(Array.from({ length: 6 }, async () => {
    while (cursor < jobs.length) {
      const job = jobs[cursor++];
      const source = fs.readFileSync(path.join(config.assetRoot, job.folder, `${job.id}.svg`));
      await sharp(source, { density: 144 }).resize(job.size, job.size).png()
        .toFile(path.join(config.outputRoot, `${job.id}-${job.folder}-${job.size}.png`));
    }
  }));
  console.log(`Rendered ${jobs.length} SVG previews.`);
}

main().catch(error => { console.error(error); process.exitCode = 1; });
