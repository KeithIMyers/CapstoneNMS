Shared-hosting layout support — extract the dist directly into public_html/ and the installer offers a one-click rearrangement that moves the Laravel app into _app/ and flattens public/ into the doc root. Future updates respect the layout automatically.

## 1.0.1-dev

- New `LayoutService` detects standard / pre-layout / shared-host layouts.
- New "Relayout for shared hosting" page in the installer wizard appears when extraction lands in `public_html/`.
- `public/index.php` now finds the bootstrap regardless of layout (`./../`, `./_app/`, or current dir).
- `UpdateService` reads the layout marker and remaps incoming `public/` contents to the doc root on shared-host installs.

## 1.0.0-dev

Initial CapstoneNMS release. Forked + rebranded from USNews.today, full licensing pipeline (Ed25519 keygen + runtime verifier, kind=production/development/trial, tier caps, paywall feature gate), web installer with preflight, web updater with signed zip apply.
