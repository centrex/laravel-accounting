<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Models\{Account, JournalEntry};
use Centrex\LaravelAccounting\Services\AccountingService;
use Livewire\{Component, WithPagination};

class JournalEntries extends Component
{
    use WithPagination;

    public $search = '';

    public $statusFilter = '';

    public $dateFrom = '';

    public $dateTo = '';

    public $showModal = false;

    // Form fields
    public $date;

    public $reference;

    public $description;

    public $lines = [];

    protected $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'account_id'  => '',
            'type'        => 'debit',
            'amount'      => 0,
            'description' => '',
        ];
    }

    public function removeLine($index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function create(): void
    {
        $this->validate([
            'date'               => 'required|date',
            'description'        => 'required|min:5',
            'lines'              => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.type'       => 'required|in:debit,credit',
            'lines.*.amount'     => 'required|numeric|min:0.01',
        ]);

        $service = app(AccountingService::class);

        try {
            $entry = $service->createJournalEntry([
                'date'        => $this->date,
                'reference'   => $this->reference,
                'description' => $this->description,
                'lines'       => $this->lines,
            ]);

            session()->flash('message', 'Journal entry created successfully!');
            $this->reset(['reference', 'description', 'lines']);
            $this->addLine();
            $this->showModal = false;
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function postEntry($id): void
    {
        $entry = JournalEntry::findOrFail($id);

        try {
            $entry->post();
            session()->flash('message', 'Journal entry posted successfully!');
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function voidEntry($id): void
    {
        $entry = JournalEntry::findOrFail($id);

        try {
            $entry->void();
            session()->flash('message', 'Journal entry voided successfully!');
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function getTotalDebits()
    {
        return collect($this->lines)->where('type', 'debit')->sum('amount');
    }

    public function getTotalCredits()
    {
        return collect($this->lines)->where('type', 'credit')->sum('amount');
    }

    public function render()
    {
        $entries = JournalEntry::query()
            ->with(['lines.account', 'creator'])
            ->when($this->search, function ($q): void {
                $q->where(function ($query): void {
                    $query->where('entry_number', 'like', '%' . $this->search . '%')
                        ->orWhere('description', 'like', '%' . $this->search . '%')
                        ->orWhere('reference', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('date', '<=', $this->dateTo))
            ->latest('date')
            ->paginate(15);

        $accounts = Account::where('is_active', true)
            ->orderBy('code')
            ->get();

        return view('accounting::livewire.journal-entries', [
            'entries'  => $entries,
            'accounts' => $accounts,
        ]);
    }
}
