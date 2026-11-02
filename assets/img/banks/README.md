# Bank logos

Save each bank's official logo here, with exactly these file names, then run `python3 tools/build.py`
(or ask Claude to). Each logo replaces that bank's name badge in the footer.

| Bank | File name |
|---|---|
| Absa | `absa.png` |
| FNB | `fnb.png` (or `.svg` / `.webp`) |
| Capitec | `capitec.svg` |
| TymeBank | `tymebank.png` |
| Nedbank | `nedbank.png` |
| Standard Bank | `standard-bank.png` |

SVG is best; otherwise a PNG at least 200px tall with a transparent or white background. Logos show 28px tall on
a white tile. Download them from each bank's own website (brand, media or press page).

The current files were trimmed to the logo itself and resized to 112px tall, so they stay sharp on all screens.
