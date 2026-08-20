{{-- Shared print styling. DomPDF supports only a narrow slice of CSS, so this stays
     deliberately plain: no flexbox, no grid, no custom properties. --}}
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
    h1 { font-size: 15px; margin: 0 0 2px; }
    .muted { color: #6b7280; }
    .meta { font-size: 9px; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 4px 5px; border-bottom: 0.5px solid #d1d5db; }
    th { background: #f3f4f6; text-align: right; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; }
    th.left, td.left { text-align: left; }
    td { text-align: right; }
    tfoot td, tfoot th { font-weight: bold; border-top: 1px solid #111827; background: #f9fafb; }
    .negative { color: #b91c1c; }
    .footnote { margin-top: 12px; font-size: 8px; color: #6b7280; }
    .cards { width: 100%; margin-bottom: 12px; }
    .cards td { border: 0.5px solid #d1d5db; padding: 7px 9px; text-align: left; width: 33%; }
    .cards .label { font-size: 8px; text-transform: uppercase; color: #6b7280; letter-spacing: 0.03em; }
    .cards .value { font-size: 13px; font-weight: bold; }
</style>
