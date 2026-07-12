<span class="font-mono text-sm {{ $row->balance > 0 ? 'text-warning' : 'text-success' }}">
    {{ number_format($row->balance, 2) }}
</span>
