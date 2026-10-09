# Inter 4.1

Source: https://github.com/rsms/inter/releases/tag/v4.1
InterVariable.woff2 is the upstream variable font, licensed under the adjacent SIL Open Font License.
InterVariable-latin.woff2 is a generated Latin subset. No separate font service is contacted.

Subset recipe: fontTools subset, WOFF2 output, hinting disabled; preserve kern, liga, clig, calt, locl, mark and mkmk layout features and variable weight range. Unicode coverage matches the Latin unicode-range in PageRenderer. Other characters use the full upstream font.
The subset is 73,500 bytes; the upstream fallback is 352,240 bytes. Tools used for subsetting are not runtime application dependencies.

Subset tooling used: fontTools 4.66.1 and Brotli 1.2.0. These tools were used outside the application repository.
