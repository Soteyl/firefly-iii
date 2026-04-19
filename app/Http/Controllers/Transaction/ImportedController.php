<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Transaction;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ImportedController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            app('view')->share('title', (string) trans('firefly.transactions'));
            app('view')->share('mainTitleIcon', 'fa-exchange');

            return $next($request);
        });
    }

    public function deleted(Request $request): Factory|View
    {
        $page     = (int) $request->get('page', 1);
        $pageSize = (int) Preferences::get('listPageSize', 50)->data;

        /** @var \FireflyIII\User $user */
        $user     = auth()->user();

        $journals = $user
            ->transactionJournals()
            ->onlyTrashed()
            ->whereHas('transactionJournalMeta', static function ($query): void {
                $query->withTrashed()->where('name', 'external_id');
            })
            ->with([
                'transactionType',
                'transactionGroup' => static function ($query): void {
                    $query->withTrashed();
                },
                'transactionJournalMeta' => static function ($query): void {
                    $query
                        ->withTrashed()
                        ->whereIn('name', ['external_id', 'monobank_pair_external_id'])
                        ->orderBy('id', 'desc')
                    ;
                },
            ])
            ->orderBy('transaction_journals.deleted_at', 'desc')
            ->paginate($pageSize, ['transaction_journals.*'], 'page', $page)
        ;

        return view('transactions.imported.deleted', [
            'subTitle' => (string) trans('firefly.deleted_imported_transactions'),
            'journals' => $journals,
        ]);
    }

    public function restore(int $journalId): RedirectResponse
    {
        /** @var \FireflyIII\User $user */
        $user    = auth()->user();

        /** @var null|TransactionJournal $journal */
        $journal = $user->transactionJournals()->onlyTrashed()->find($journalId);

        if (!$journal instanceof TransactionJournal) {
            session()->flash('error', (string) trans('firefly.deleted_imported_transaction_not_found'));

            return redirect()->route('transactions.imported.deleted');
        }

        DB::transaction(static function () use ($user, $journal): void {
            if (null !== $journal->transaction_group_id) {
                /** @var null|TransactionGroup $group */
                $group = TransactionGroup::withTrashed()
                    ->where('user_id', $user->id)
                    ->find($journal->transaction_group_id)
                ;

                if ($group instanceof TransactionGroup && null !== $group->deleted_at) {
                    $group->restore();
                }
            }

            $journal->restore();

            Transaction::withTrashed()
                ->where('transaction_journal_id', $journal->id)
                ->restore()
            ;

            TransactionJournalMeta::withTrashed()
                ->where('transaction_journal_id', $journal->id)
                ->restore()
            ;
        });

        Preferences::mark();
        session()->flash('success', (string) trans('firefly.deleted_imported_transaction_restored'));

        return redirect()->route('transactions.imported.deleted');
    }
}
