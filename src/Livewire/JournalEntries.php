<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, JournalEntry};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\{Component, WithPagination};

class JournalEntries extends Component
{
    use WithPagination;

    public $search = '';

    public $statusFilter = '';

    public $dateFrom = '';

    public $dateTo = '';

    public $showModal = false;

    public $showDetailModal = false;

    public $journalEntryId = null;

    public $viewingEntry = null;

    // Form fields
    public $date;

    public $reference;

    public $description;

    public $lines = [];

    protected $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->resetForm();
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

    public function resetForm(): void
    {
        $this->resetValidation();
        $this->journalEntryId = null;
        $this->date = now()->format('Y-m-d');
        $this->reference = null;
        $this->description = null;
        $this->lines = [];
        $this->addLine();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $entry = JournalEntry::query()->with('lines')->findOrFail($id);

        if (($entry->status->value ?? $entry->status) !== 'draft') {
            session()->flash('error', 'Only draft journal entries can be edited.');

            return;
        }

        $this->resetValidation();
        $this->journalEntryId = $entry->id;
        $this->date = $entry->date?->format('Y-m-d');
        $this->reference = $entry->reference;
        $this->description = $entry->description;
        $this->lines = $entry->lines
            ->map(fn (mixed $line): array => [
                'account_id' => $line->account_id,
                'type' => $line->type,
                'amount' => (float) $line->amount,
                'description' => $line->description,
            ])
            ->values()
            ->all();

        if ($this->lines === []) {
            $this->addLine();
        }

        $this->showModal = true;
    }

    public function viewEntry(int $id): void
    {
        $this->viewingEntry = JournalEntry::query()
            ->with(['lines.account', 'creator', 'approver'])
            ->findOrFail($id);

        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->viewingEntry = null;
    }

    public function editViewingEntry(): void
    {
        if (! $this->viewingEntry) {
            return;
        }

        $id = $this->viewingEntry->id;
        $this->closeDetailModal();
        $this->openEditModal($id);
    }

    public function removeLine($index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        $this->validate([
            'date'               => 'required|date',
            'description'        => 'required|min:5',
            'lines'              => 'required|array|min:2',
            'lines.*.account_id' => ['required', Rule::exists((new Account())->getTable(), 'id')],
            'lines.*.type'       => 'required|in:debit,credit',
            'lines.*.amount'     => 'required|numeric|min:0.01',
        ]);

        if (abs($this->getTotalDebits() - $this->getTotalCredits()) >= 0.01) {
            $this->addError('lines', 'Journal entry must be balanced before saving.');

            return;
        }

        try {
            if ($this->journalEntryId) {
                DB::transaction(function (): void {
                    $entry = JournalEntry::query()->with('lines')->findOrFail($this->journalEntryId);

                    if (($entry->status->value ?? $entry->status) !== 'draft') {
                        throw new \RuntimeException('Only draft journal entries can be edited.');
                    }

                    $entry->update([
                        'date' => $this->date,
                        'reference' => $this->reference,
                        'description' => $this->description,
                    ]);

                    $entry->lines()->delete();

                    foreach ($this->lines as $line) {
                        $entry->lines()->create([
                            'account_id' => $line['account_id'],
                            'type' => strtolower((string) $line['type']),
                            'amount' => $line['amount'],
                            'description' => $line['description'] ?? null,
                        ]);
                    }
                });

                session()->flash('message', 'Journal entry updated successfully!');
            } else {
                $service = app(Accounting::class);
                $service->createJournalEntry([
                    'date'        => $this->date,
                    'reference'   => $this->reference,
                    'description' => $this->description,
                    'lines'       => $this->lines,
                ]);

                session()->flash('message', 'Journal entry created successfully!');
            }

            $this->resetForm();
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

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.journal-entries', [
            'entries'  => $entries,
            'accounts' => $accounts,
        ])->layout($layout, ['title' => __('Journal Entries')]);
    }
}
