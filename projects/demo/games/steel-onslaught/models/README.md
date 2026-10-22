# public/models/

Drop-in folder for the game's 3D assets. Structure mirrors `source_img/` and the
asset manifest in `src/assets/manifest.ts`.

## How it works

Every model has a stable id in the manifest (e.g. `hero`, `simple_barrel`,
`shotgun`). The loader (`src/assets/AssetLoader.ts`) resolves an id to:

- `public/models/<category>/<id>.glb` if the file exists, **otherwise**
- a coloured primitive placeholder (shape/size/colour from the manifest).

So the game runs today with placeholders, and each real model activates the
moment you drop `<id>.glb` into the right folder — **no code change needed**.

## Adding a model

1. Export as **GLB** (glTF binary), Y-up, metres, origin at the base.
2. Name it exactly `<id>.glb` (match the manifest id).
3. Put it in the matching category folder below.

## Categories

| Folder       | Source art (`source_img/`) | Examples |
|--------------|-----------------------------|----------|
| `hero/`      | hero.png                    | `hero.glb` |
| `enemies/`   | enemies/                    | `melee_0.glb`, `boss.glb` |
| `weapons/`   | weapons/                    | `shotgun.glb`, `minigun.glb` |
| `boxes/`     | boxes/                      | `simple_barrel.glb`, `chest.glb` |
| `buildings/` | buildings/                  | `reactor.glb`, `gates.glb` |
| `loot/`      | loot/                       | `coin.glb`, `medicine.glb` |
| `scene/`     | scene/                      | `wall.glb`, `forklift.glb` |

Keep individual GLBs small (target the ~10 MB total build budget). Prefer shared
materials and low-poly, toy-like shapes per the art direction.

## Optimizing models

Run the optimizer (glTF-Transform + meshoptimizer). It's **smart by default**:
for game models the weight is almost always in textures, not polygons, so it

1. resizes textures to WebP (the big saving),
2. Meshopt-compresses the geometry — shrinks the file with **no polygon loss**,
3. decimates (Blender-Decimate-style) **only** if a model is over the triangle
   budget (`--max-tris`, default 20k). Low-poly models keep their exact geometry.

The loader already registers the Meshopt decoder, so output loads with no code
change.

```bash
npm run models:compress               # smart: textures + keep geometry, in place
npm run models:compress:dry           # analyze & report sizes only, write nothing
node scripts/compress-models.mjs --max-tris 8000       # cap heavy models at 8k tris
node scripts/compress-models.mjs --tex-size 2048 public/models/buildings
```

Texture-heavy exports (e.g. Meshy AI) are usually ~95% textures — the WebP resize
alone can drop an 8 MB model to ~350 KB, without touching its ~2k polygons.

- Geometry is kept by default; `--max-tris <n>` sets the auto-decimate threshold,
  `--ratio <0..1>` forces a keep-fraction on every model, `--no-simplify` never
  decimates, `--error <n>` caps simplify distortion. Textures → WebP by default
  (`--tex-size` / `--tex-quality` tune it; `--no-textures` skips). `--dry` previews.
- In-place mode backs up each original to `.model-originals/` at the project
  root — outside `public/`, so Vite never bundles it (gitignored). Pass
  `--out <dir>` to write copies elsewhere instead.

Run `node scripts/compress-models.mjs --help` for all options.
