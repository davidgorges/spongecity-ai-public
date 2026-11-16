# Garden models

Model libraries for the scenery: one GLB per library, one node per variant
(`ModelLibrary`, `Core\Configurator\Scene\GardenModels`).

| File | Variants | Triangles per variant |
|---|---|---|
| `garden_trees.glb` | `tree_01` | ~40 000 |
| `garden_bushes.glb` | `bush_01` … `bush_15` | ~14 000 |
| `garden_plants.glb` | `plant_01`, `plant_02` | ~20 000 |

`garden_trees.glb` was simplified from the original 122 000 triangles, because a
site imported from OpenStreetMap stands up to 30 of these trees in one view and
draws each of them three times (view, shadow map, ambient occlusion):

    npx @gltf-transform/cli@4 simplify garden_trees.glb out.glb --ratio 0.25 --error 0.02

A close-up looks the same (a few of the finest twigs are gone); on an M4 MacBook
at 2× it saves ~3.5 ms of GPU time per frame in the aerial view
(`bin/render-loop <plan> --perf --dpr=2`).
