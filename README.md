# M4P WebP Converter for PrestaShop 8 & 9

**Convert PrestaShop product images to WebP and serve them automatically — smaller pages, faster loading, better Core Web Vitals and SEO rankings.**

> **Meta description (155 chars):** Convert PrestaShop product images to WebP automatically. Cut image weight up to 80%, speed up your store, improve LCP and Core Web Vitals for SEO.

---

## Why WebP matters for your store's SEO

Images are typically **50–70% of the total page weight** of a PrestaShop shop. Google's Core Web Vitals — in particular **Largest Contentful Paint (LCP)** — are a confirmed ranking signal, and on product and category pages the LCP element is almost always an image.

WebP delivers the same visual quality as JPEG and PNG at **25–80% smaller file sizes**. Converting your catalogue is one of the highest-impact, lowest-risk page speed optimisations available to an e-commerce store:

- **Faster LCP and page load times** — directly improves your Core Web Vitals score
- **Better mobile experience** — less data over slow 3G/4G connections
- **Higher conversion rates** — every 100 ms of added latency measurably reduces sales
- **Lower bandwidth and CDN costs** — fewer bytes served per visit
- **Improved crawl efficiency** — lighter pages let Googlebot crawl more of your catalogue

## What the module does

M4P WebP Converter generates a WebP twin for every product image on your store and serves it to browsers that support the format — while leaving your original JPEG and PNG files completely untouched.

### Key features

- **Bulk conversion with live progress** — convert your entire catalogue from the back office with a real-time progress bar, per-image log and running counters
- **Automatic conversion of new uploads** — every image added or updated in the back office is converted on the spot, on PrestaShop 8 and PrestaShop 9 (CQRS) alike
- **Full thumbnail coverage** — converts every generated image size (`home_default`, `large_default`, `cart_default`, and any custom size), not just the original file
- **Automatic front office delivery** — `<img>` tags are wrapped in a `<picture>` element offering the WebP source, with a transparent fallback to the original image
- **Configurable quality** — tune the compression level from 1 to 100 to balance file size against visual fidelity
- **Transparency preserved** — the alpha channel of PNG and GIF images survives conversion intact
- **Batch limits** — cap how many products are processed per run so shared hosting never times out
- **Active products only** — optionally skip images belonging to disabled products
- **Non-destructive** — original files are never modified, moved or deleted; uninstalling the module changes nothing about your images

### Zero-risk browser fallback

The module never replaces your images. It adds a `<picture>` wrapper:

```html
<picture>
    <source type="image/webp" srcset="/img/p/1/2/3/4/1234-new_format.webp">
    <img src="/img/p/1/2/3/4/1234.jpg" alt="Product name">
</picture>
```

Browsers that understand WebP — Chrome, Firefox, Edge, Safari 14+, and every modern mobile browser, together well over 95% of global traffic — load the smaller file. Anything else silently loads your original image. Nothing breaks, ever.

## Compatibility

| | |
|---|---|
| PrestaShop | 8.0 – 9.x |
| PHP | 7.4+ |
| Requirements | GD extension compiled with WebP support (`imagewebp`) |
| Multistore | Compatible |
| Themes | Works with any theme — no template overrides required |

The module performs no core overrides and adds no database tables.

## Installation

1. Upload and install the module from **Modules → Module Manager**.
2. Open the module configuration page.
3. Set your preferred **WebP quality** (80–90 is recommended for product photography).
4. Click **Convert images for all products** and wait for the progress bar to complete.
5. Leave **Serve WebP on the front office** enabled — your shop now delivers WebP.

If installation fails with a GD error, ask your host to enable WebP support in the PHP GD extension.

## Configuration options

| Setting | Description |
|---|---|
| **WebP Quality (1–100)** | Compression level of the generated file. 80–90 gives a large size reduction with no visible loss. |
| **Max products per bulk run** | Limits how many products a single bulk run processes. Set to 0 to process the whole catalogue. |
| **Convert thumbnails** | Also converts every generated thumbnail size. Required for WebP to be used on category and listing pages. |
| **Serve WebP on the front office** | Wraps front office images in a `<picture>` element offering the WebP source. |
| **Force regenerate** | Overwrites existing WebP files — use after changing the quality setting. |
| **Active products only** | Restricts conversion to images of active products. |

## Frequently asked questions

**Will this delete or modify my original images?**
No. The module only writes new `-new_format.webp` files alongside your originals. Your JPEG and PNG files are never touched.

**What happens to visitors on browsers without WebP support?**
They receive the original image exactly as before, thanks to the `<picture>` fallback.

**Do I need to re-run the conversion after adding a product?**
No. New and updated images are converted automatically as soon as they are uploaded.

**Does it work with a CDN?**
Yes. The generated WebP files live next to the originals in your `img/` directory and are served through the same URLs and CDN rules.

**How much disk space does it need?**
Roughly 20–50% of the size of your existing product image folder, since WebP files are considerably smaller than their sources.

**Can I undo it?**
Yes. Turn off front office delivery to instantly stop serving WebP, or uninstall the module. The generated `.webp` files can be deleted at any time with no impact on your shop.

---

**Keywords:** PrestaShop WebP module, WebP converter PrestaShop 8, image optimization PrestaShop, Core Web Vitals PrestaShop, page speed optimization, LCP optimization, product image compression, PrestaShop SEO module.

© modules4presta.io — All rights reserved.
