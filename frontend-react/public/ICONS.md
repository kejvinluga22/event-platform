# PWA Icons

The `manifest.json` references `icon-192.png` and `icon-512.png`.

A placeholder SVG (`icon-placeholder.svg`) is provided. Generate proper PNGs from your brand logo:

```bash
# Using ImageMagick
convert icon-placeholder.svg -resize 192x192 icon-192.png
convert icon-placeholder.svg -resize 512x512 icon-512.png
```

Or use any design tool to export at these dimensions.

The icons are used for:
- Home screen shortcut (iOS/Android)
- App switcher
- Splash screen
- Browser tab
