<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    <title>@yield('title', config('chargeguard.name'))</title>
    @unless(request()->attributes->get('local_demo'))
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="{{ config('shopify.api_key') }}"></script>
    @endunless
    <script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script>
    <script src="/js/chargeguard.js" defer></script>
    <style>
        body{margin:0;background:#f1f1f1;color:#303030;font:14px system-ui,sans-serif}
        .metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:16px}
        .metric-value{font-size:28px;font-weight:650;margin:8px 0}
        .scroll{overflow-x:auto} table{border-collapse:collapse;width:100%;text-align:left}
        th,td{padding:12px;border-bottom:1px solid #e3e3e3;vertical-align:top} th{font-size:12px;color:#616161}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
        label{display:block;margin-bottom:16px} input:not([type=checkbox]),select,textarea{display:block;box-sizing:border-box;width:100%;padding:9px;margin-top:6px;border:1px solid #8a8a8a;border-radius:8px;background:white;color:inherit;font:inherit}
        input[type=checkbox]{margin-right:8px} textarea{min-height:250px}
        button{background:#303030;color:white;border:0;border-radius:8px;padding:10px 16px;font:inherit;cursor:pointer}
        button:disabled{opacity:.5;cursor:wait}.actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:16px 0}
        .muted{color:#616161} .preview{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px}
        a{color:#005bd3} dl{display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:12px}dd{margin:0;overflow-wrap:anywhere}
        s-section{display:block;margin-bottom:16px} code{overflow-wrap:anywhere}
    </style>
</head>
<body @if(request()->attributes->get('local_demo')) data-local-demo @endif>
    @if(request()->attributes->get('local_demo'))
    <s-banner tone="warning" heading="Local read-only demo">Sample data only. Open the installed app in Shopify to edit templates or send test emails.</s-banner>
    <nav class="actions"><a href="/demo/dashboard">Dashboard</a><a href="/demo/disputes">Disputes</a><a href="/demo/templates">Templates</a><a href="/demo/test-automation">Test Automation</a><a href="/demo/settings">Settings</a></nav>
    @endif
    @unless(request()->attributes->get('local_demo'))
    <s-app-nav>
        <s-link href="/dashboard" rel="home">Home</s-link><s-link href="/dashboard">Dashboard</s-link><s-link href="/disputes">Disputes</s-link><s-link href="/templates">Email Templates</s-link>
        <s-link href="/test-automation">Test Automation</s-link><s-link href="/email-logs">Email Logs</s-link><s-link href="/settings">Settings</s-link><s-link href="/billing">Billing</s-link>
    </s-app-nav>
    @endunless
    <s-page heading="@yield('title', config('chargeguard.name'))">
        @if(config('chargeguard.test_mode') || $shop->settings?->test_mode)
            <s-banner tone="warning" heading="TEST MODE">Production customer emails are blocked. Test emails are labelled and logged separately.@if(app()->environment('production')) Production is running with test mode enabled.@endif</s-banner>
        @endif
        @if(!$shop->settings?->onboarded_at)
            <s-banner heading="Finish setting up {{ config('chargeguard.name') }}">Automation starts disabled. <s-link href="/onboarding">Open your setup checklist</s-link>.</s-banner>
        @endif
        <div id="notice" role="status" aria-live="polite"></div>
        @yield('content')
        <s-paragraph>Shopify Payments disputes only · {{ $shop->store_name ?: $shop->shop_domain }}</s-paragraph>
    </s-page>
</body>
</html>
