{{--
    Shared "From" header for printed documents (Livewire\InvoiceDetails::exportPdf(),
    BillDetails::exportPdf()). Expects: $company (config('accounting.company')),
    $documentTitle, $documentNumber, $statusLabel, $generatedAt.
--}}
<div class="header">
    <div class="col">
        @if(!empty($company['logo']) && is_file($company['logo']))
            <img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}" style="max-height: 46px; margin-bottom: 8px;">
        @endif
        <div style="font-size: 15px; font-weight: bold;">{{ $company['name'] }}</div>
        @if(!empty($company['address']))
            <div class="muted">{{ $company['address'] }}</div>
        @endif
        @if(!empty($company['email']) || !empty($company['phone']))
            <div class="muted">{{ collect([$company['email'] ?? null, $company['phone'] ?? null])->filter()->implode('  ·  ') }}</div>
        @endif
        @if(!empty($company['tax_id']))
            <div class="muted">Tax ID: {{ $company['tax_id'] }}</div>
        @endif
    </div>
    <div class="col right">
        <h1>{{ $documentTitle }}</h1>
        <div class="mono" style="font-size: 14px;">{{ $documentNumber }}</div>
        <div class="badge">{{ $statusLabel }}</div>
        <div class="meta">Generated {{ $generatedAt->format('F d, Y h:i A') }}</div>
    </div>
</div>
