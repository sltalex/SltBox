# Aquarium asset sources and licenses

The legacy root-level photographic assets below are adapted from photographs retrieved through the Wikimedia Commons API. Their original authors and license pages are listed below. The transparent PNGs are crops/color-preserving cutouts prepared for the SltBox aquarium scene; `background.png` is a resized crop of the underwater photograph. They remain available for compatibility.

## Original SVG collection

The 250 naturalistic files in `Svg/` (150 fish, 50 aquatic plants, 50 rockscapes) are original
naturalistic vector illustrations authored for SltBox. The matching 250 PNGs in
`Vector/` are transparent raster exports of those SVGs, not photographs. They
contain no embedded third-party photos, external fonts or remote resources.
These originals and their exports are distributed under GPL-3.0-or-later, like
the project's original procedural artwork. See the repository `LICENSE`.

Reproduce them using `scripts/generate-aquarium-svg-assets.py` and the
`scripts/aquarium_svg/` modules. The full inventory and subject names are in
`docs/design/aquarium-realistic-expansion-plan.json` and
`docs/design/aquarium-fish-expansion-plan.json`. The legacy photographic
licenses below apply only to the corresponding root-level files, not to the new
vector originals or their PNG exports.

## Original cartoon SVG collection

An additional 30 files in `Svg/` and their 30 transparent exports in `Vector/`
depict 10 shrimp, 10 crabs and 10 shellfish in a deliberately cartoon style.
They are original SltBox vector artwork, distributed under GPL-3.0-or-later.
They contain no third-party photos, embedded bitmaps, fonts or remote resources.

The inventory is `docs/design/aquarium-cartoon-expansion-plan.json`. Reproduce
the artwork with `scripts/generate-aquarium-svg-assets.py --category shrimp`,
`--category crabs`, or `--category shellfish`; the corresponding modules live in
`scripts/aquarium_svg/`. Existing shrimp/crab/shellfish item IDs now resolve to
these vector exports. Their root-level legacy PNGs and attributions remain
unchanged for compatibility.

An additional 60 cartoon SVG originals and transparent PNG exports depict
20 corals, 20 algae and 20 snails. These original SltBox illustrations also
use GPL-3.0-or-later and contain no external imagery or resources. Inventories
are in `docs/design/aquarium-reef-expansion-plan.json` and
`docs/design/aquarium-snail-expansion-plan.json`; reproduce with categories
`corals`, `algae`, and `snails`. In total the collection ships 340 SVG/PNG pairs.
Fish habitat groups are browsing labels, not biological cohabitation guidance.

## Aquarium card-pack artwork

`aquarium-card-pack.svg` and its transparent `aquarium-card-pack.png` export
are original SltBox vector artwork under GPL-3.0-or-later. The three stacked
ocean collectible cards share the aquarium's deep teal, aqua and pale-gold
palette. They contain no third-party images, fonts or external resources.
Reproduce the PNG with `scripts/generate-aquarium-card-pack.py` using the same
pinned resvg/Pillow renderer as the species artwork. The shop, backpack and
pack-opening dialog reuse this artwork; it is not a placeable scene species.

## Legacy photographic assets

| Shipped file | Source | Author | License | Adaptation |
| --- | --- | --- | --- | --- |
| `clownfish.png` | [Common clownfish](https://commons.wikimedia.org/wiki/File:Common_clownfish.jpg) | Janderk | Public domain | Fish crop, horizontally oriented |
| `blue-tang.png` | [Paracanthurus-hepatus-paletten-doktorfisch](https://commons.wikimedia.org/wiki/File:Paracanthurus-hepatus-paletten-doktorfisch.jpg) | Holger Krisp | CC BY 3.0 | Fish crop, horizontally oriented |
| `butterflyfish.png` | [Pez mariposa arábigo](https://commons.wikimedia.org/wiki/File:Pez_mariposa_ar%C3%A1bigo_(Chaetodon_melapterus),_islas_Ad_Dimaniyat,_Om%C3%A1n,_2024-08-13,_DD_77.jpg) | Diego Delso | CC BY-SA 4.0 | Fish crop, horizontally oriented |
| `seagrass.png` | [Caulerpa prolifera](https://commons.wikimedia.org/wiki/File:Caulerpa_prolifera133.jpg) | Nanosanchez | Public domain | Plant crop and transparent background |
| `fan-algae.png` | [Udotea flabellum](https://commons.wikimedia.org/wiki/File:Udotea_flabellum_(mermaid%27s_fan_alga)_(San_Salvador_Island,_Bahamas)_1_(15860589498).jpg) | James St. John | CC BY 2.0 | Algae crop and transparent background |
| `reef-rock.png` | [Crinoids in fossiliferous limestone 1](https://commons.wikimedia.org/wiki/File:Crinoids_in_fossiliferous_limestone_1.jpg) | James St. John | CC BY 2.0 | Textured rock crop and transparent background |
| `rock-arch.png` | [Rock Arch](https://commons.wikimedia.org/wiki/File:Rock_Arch_-_geograph.org.uk_-_592784.jpg) | Peter Fairhurst | CC BY-SA 2.0 | Arch crop and transparent background |
| `background.png` | [Underwater Shots of Barren Sandy Sea floor in Bali Diving Sites](https://commons.wikimedia.org/wiki/File:Underwater_Shots_of_Barren_Sandy_Sea_floor_in_Bali_Diving_Sites.png) | User:MrAlanKoh | CC BY 4.0 | Cropped/resized to the 840:600 aquarium canvas |
| `angelfish.png` | [Pterophyllum scalare 03](https://commons.wikimedia.org/wiki/File:Pterophyllum_scalare_03.jpg) | Versions111 | CC BY 4.0 | Transparent photographic crop |
| `lionfish.png` | [Pterois volitans Manado-e](https://commons.wikimedia.org/wiki/File:Pterois_volitans_Manado-e.jpg) | Jens Petersen, edit by Olegiwit | CC BY 2.5 | Transparent photographic crop |
| `pufferfish.png` | [Arothron diadematus](https://commons.wikimedia.org/wiki/File:Pez_globo_(Arothron_diadematus),_mar_Rojo,_Egipto,_2023-04-17,_DD_19.jpg) | Diego Delso | CC BY-SA 4.0 | Transparent photographic crop |
| `seahorse.png` | [Longsnout Seahorse](https://commons.wikimedia.org/wiki/File:Longsnout_Seahorse_(Hippocampus_reidi).jpg) | Cliff | CC BY 2.0 | Transparent photographic crop |
| `yellow-tang.png` | [Yellow tang in the Leningrad zoo 7](https://commons.wikimedia.org/wiki/File:Yellow_tang_in_the_Leningrad_zoo_7.jpg) | Горбунова М.С. | CC BY-SA 4.0 | Transparent photographic crop |
| `royal-gramma.png` | [Gramma loreto, Adult](https://commons.wikimedia.org/wiki/File:Pez_abuela_real_(Gramma_loreto),_San_Pedro,_Belice,_2025-12-18,_DD_31.jpg) | Diego Delso | CC BY-SA 4.0 | Transparent photographic crop |
| `mandarinfish.png` | [Synchiropus splendidus 2](https://commons.wikimedia.org/wiki/File:Synchiropus_splendidus_2_Luc_Viatour.jpg) | Luc Viatour | CC BY-SA 3.0 | Transparent photographic crop |
| `moorish-idol.png` | [Idole des Maures](https://commons.wikimedia.org/wiki/File:Idole_des_Maures_(Zanclus_cornutus).jpg) | Philippe Bourjon | CC BY-SA 3.0 | Transparent photographic crop |
| `cardinalfish.png` | [Sphaeramia nematoptera](https://commons.wikimedia.org/wiki/File:Apogonidae_Sphaeramia_nematoptera_3.jpg) | NasserHalaweh | CC BY-SA 4.0 | Transparent photographic crop |
| `java-fern.png` | [Java Fern in the wild](https://commons.wikimedia.org/wiki/File:Java_Fern_(Microsorum_pteropus)_growing_in_the_wild..jpg) | Fhurzan | CC BY-SA 3.0 | Transparent photographic crop |
| `hornwort.png` | [Ceratophyllum demersum](https://commons.wikimedia.org/wiki/File:Ceratophyllum_demersum,_hornwort,_rigid_hornwort,_coontail,_or_coon%27s_tail._.jpg) | Renjusplace | CC BY-SA 3.0 | Transparent photographic crop |
| `amazon-sword.png` | [Echinodorus amazonicus](https://commons.wikimedia.org/wiki/File:Echinodorus_amazonicus-2-veda_aquarium-bengaluru-India.jpg) | Yercaud-elango | CC BY 4.0 | Transparent photographic crop |
| `vallisneria.png` | [Vallisneria nana](https://commons.wikimedia.org/wiki/File:Vallisneria_nana-3-veda_aquarium-bengaluru-India.jpg) | Yercaud-elango | CC BY 4.0 | Transparent photographic crop |
| `porcelain-crab.png` | [Petrolisthes elongatus](https://commons.wikimedia.org/wiki/File:Petrolisthes_elongatus_(New_Zealand_half_crab).JPG) | GrahamBould | Public domain | Transparent photographic crop |
| `emerald-crab.png` | [Emerald Crab munching on Hair Algae](https://commons.wikimedia.org/wiki/File:Emerald_Crab_munching_on_Hair_Algae.jpg) | aquarist.me | CC BY 2.0 | Transparent photographic crop |
| `hermit-crab.png` | [Hairy yellow hermit crab](https://commons.wikimedia.org/wiki/File:Hairy_yellow_hermit_crab_Aniculus_maximus.jpg) | Vardhanjp | CC BY-SA 4.0 | Transparent photographic crop |
| `fiddler-crab.png` | [Compressed fiddler crab](https://commons.wikimedia.org/wiki/File:Compressed_fiddler_crab_(Uca_coarctata)_male.jpg) | Charles J. Sharp | CC BY-SA 4.0 | Transparent photographic crop |

Attribution and license text are retained here because several sources require attribution and share-alike terms. No source is presented as an original SltBox photograph.

The following remaining assets are original procedural illustrations generated by
`scripts/generate-aquarium-illustrations.py`; they are authored for SltBox and
do not incorporate third-party image data:

| Shipped files | Source | Author | License | Adaptation |
| --- | --- | --- | --- | --- |
| `anubias.png` | Original procedural illustration | SltBox project | GPL-3.0-or-later | Hand-drawn transparent aquatic plant |
| `cherry-shrimp.png`, `amano-shrimp.png` | Original procedural illustration | SltBox project | GPL-3.0-or-later | Hand-drawn transparent shrimp illustrations |
| `scallop.png`, `oyster.png` | Original procedural illustration | SltBox project | GPL-3.0-or-later | Hand-drawn transparent shellfish illustrations |
