# Skies for photo mode

CC0 HDRIs from Poly Haven (https://polyhaven.com, licence: CC0), 2k, one per mood:

| File | Source |
|---|---|
| `sky_day.hdr` | kloofendal_48d_partly_cloudy_puresky |
| `sky_sunset.hdr` | belfast_sunset_puresky |
| `sky_evening.hdr` | qwantani_dusk_2_puresky |

Loaded only when a photo is taken (`photo_mode.js`).

`sky_studio.hdr` (the studio setting, see `studio_environment.js`) is generated: a neutral cove
with a key softbox, a fill strip and a top light, written by `python3 bin/studio-hdri.py
assets/configurator/hdri/sky_studio.hdr`.
