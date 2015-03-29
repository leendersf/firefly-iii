<?php

namespace FireflyIII\Repositories\Account;

use App;
use Auth;
use Carbon\Carbon;
use Config;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\Preference;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Log;
use Session;
use Steam;

/**
 * Class AccountRepository
 *
 * @package FireflyIII\Repositories\Account
 */
class AccountRepository implements AccountRepositoryInterface
{

    /**
     * @return int
     */
    public function countAssetAccounts()
    {
        return Auth::user()->accounts()->accountTypeIn(['Asset account', 'Default account'])->count();
    }

    /**
     * @param Account $account
     *
     * @return boolean
     */
    public function destroy(Account $account)
    {
        $account->delete();

        return true;
    }

    /**
     * @param Preference $preference
     *
     * @return Collection
     */
    public function getFrontpageAccounts(Preference $preference)
    {
        if ($preference->data == []) {
            $accounts = Auth::user()->accounts()->accountTypeIn(['Default account', 'Asset account'])->orderBy('accounts.name', 'ASC')->get(['accounts.*']);
        } else {
            $accounts = Auth::user()->accounts()->whereIn('id', $preference->data)->orderBy('accounts.name', 'ASC')->get(['accounts.*']);
        }

        return $accounts;
    }

    /**
     * This method is used on the front page where (in turn) its viewed journals-tiny.php which (in turn)
     * is almost the only place where formatJournal is used. Aka, we can use some custom querying to get some specific.
     * fields using left joins.
     *
     * @param Account $account
     * @param Carbon  $start
     * @param Carbon  $end
     *
     * @return mixed
     */
    public function getFrontpageTransactions(Account $account, Carbon $start, Carbon $end)
    {
        return Auth::user()
                   ->transactionjournals()
                   ->with(['transactions'])
                   ->leftJoin('transactions', 'transactions.transaction_journal_id', '=', 'transaction_journals.id')
                   ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')->where('accounts.id', $account->id)
                   ->leftJoin('transaction_currencies', 'transaction_currencies.id', '=', 'transaction_journals.transaction_currency_id')
                   ->leftJoin('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
                   ->before($end)
                   ->after($start)
                   ->orderBy('transaction_journals.date', 'DESC')
                   ->orderBy('transaction_journals.id', 'DESC')
                   ->take(10)
                   ->get(['transaction_journals.*', 'transaction_currencies.symbol', 'transaction_types.type']);
    }

    /**
     * @param Account $account
     * @param int     $page
     *
     * @return mixed
     */
    public function getJournals(Account $account, $page)
    {
        $offset = ($page - 1) * 50;
        $query  = Auth::user()
                      ->transactionJournals()
                      ->withRelevantData()
                      ->leftJoin('transactions', 'transactions.transaction_journal_id', '=', 'transaction_journals.id')
                      ->where('transactions.account_id', $account->id)
                      ->orderBy('transaction_journals.date', 'DESC')
                      ->orderBy('transaction_journals.order', 'ASC')
                      ->orderBy('transaction_journals.id', 'DESC');

        $query->before(Session::get('end', Carbon::now()->endOfMonth()));
        $query->after(Session::get('start', Carbon::now()->startOfMonth()));
        $count     = $query->count();
        $set       = $query->take(50)->offset($offset)->get(['transaction_journals.*']);
        $paginator = new LengthAwarePaginator($set, $count, 50, $page);

        return $paginator;


    }

    /**
     * Get savings accounts and the balance difference in the period.
     *
     * @return Collection
     */
    public function getSavingsAccounts()
    {
        $accounts = Auth::user()->accounts()->accountTypeIn(['Default account', 'Asset account'])->orderBy('accounts.name', 'ASC')
                        ->leftJoin('account_meta', 'account_meta.account_id', '=', 'accounts.id')
                        ->where('account_meta.name', 'accountRole')
                        ->where('account_meta.data', '"savingAsset"')
                        ->get(['accounts.*']);
        $start    = clone Session::get('start');
        $end      = clone Session::get('end');

        $accounts->each(
            function (Account $account) use ($start, $end) {
                $account->startBalance = Steam::balance($account, $start);
                $account->endBalance   = Steam::balance($account, $end);

                // diff (negative when lost, positive when gained)
                $diff = $account->endBalance - $account->startBalance;

                if ($diff < 0 && $account->startBalance > 0) {
                    // percentage lost compared to start.
                    $pct = (($diff * -1) / $account->startBalance) * 100;
                } else {
                    if ($diff >= 0 && $account->startBalance > 0) {
                        $pct = ($diff / $account->startBalance) * 100;
                    } else {
                        $pct = 100;
                    }
                }
                $pct                 = $pct > 100 ? 100 : $pct;
                $account->difference = $diff;
                $account->percentage = round($pct);
            }
        );

        return $accounts;
    }

    /**
     * @param Account $account
     *
     * @return float
     */
    public function leftOnAccount(Account $account)
    {
        $balance = \Steam::balance($account);
        /** @var PiggyBank $p */
        foreach ($account->piggybanks()->get() as $p) {
            $balance -= $p->currentRelevantRep()->currentamount;
        }

        return $balance;

    }

    /**
     * @param Account $account
     *
     * @return TransactionJournal|null
     */
    public function openingBalanceTransaction(Account $account)
    {
        return TransactionJournal::accountIs($account)
                                 ->orderBy('transaction_journals.date', 'ASC')
                                 ->orderBy('created_at', 'ASC')
                                 ->first(['transaction_journals.*']);
    }

    /**
     * @param array $data
     *
     * @return Account;
     */
    public function store(array $data)
    {
        $newAccount = $this->storeAccount($data);
        $this->storeMetadata($newAccount, $data);


        // continue with the opposing account:
        if ($data['openingBalance'] != 0) {
            $type         = $data['openingBalance'] < 0 ? 'expense' : 'revenue';
            $opposingData = [
                'user'        => $data['user'],
                'accountType' => $type,
                'name'        => $data['name'] . ' initial balance',
                'active'      => false,
            ];
            $opposing     = $this->storeAccount($opposingData);
            $this->storeInitialBalance($newAccount, $opposing, $data);

        }

        return $newAccount;

    }

    /**
     * @param Account $account
     * @param array   $data
     */
    public function update(Account $account, array $data)
    {
        // update the account:
        $account->name   = $data['name'];
        $account->active = $data['active'] == '1' ? true : false;
        $account->save();

        // update meta data:
        $this->updateMetadata($account, $data);

        $openingBalance = $this->openingBalanceTransaction($account);

        // if has openingbalance?
        if ($data['openingBalance'] != 0) {
            // if opening balance, do an update:
            if ($openingBalance) {
                // update existing opening balance.
                $this->updateInitialBalance($account, $openingBalance, $data);
            } else {
                // create new opening balance.
                $type         = $data['openingBalance'] < 0 ? 'expense' : 'revenue';
                $opposingData = [
                    'user'        => $data['user'],
                    'accountType' => $type,
                    'name'        => $data['name'] . ' initial balance',
                    'active'      => false,
                ];
                $opposing     = $this->storeAccount($opposingData);
                $this->storeInitialBalance($account, $opposing, $data);
            }

        } else {
            // opening balance is zero, should we delete it?
            if ($openingBalance) {
                // delete existing opening balance.
                $openingBalance->delete();
            }
        }

        return $account;
    }

    /**
     * @param array $data
     *
     * @return Account
     */
    protected function storeAccount(array $data)
    {
        $type        = Config::get('firefly.accountTypeByIdentifier.' . $data['accountType']);
        $accountType = AccountType::whereType($type)->first();
        $newAccount  = new Account(
            [
                'user_id'         => $data['user'],
                'account_type_id' => $accountType->id,
                'name'            => $data['name'],
                'active'          => $data['active'] === true ? true : false,
            ]
        );

        if (!$newAccount->isValid()) {
            // does the account already exist?
            $existingAccount = Account::where('user_id', $data['user'])->where('account_type_id', $accountType->id)->where('name', $data['name'])->first();
            if (!$existingAccount) {
                Log::error('Account create error: ' . $newAccount->getErrors()->toJson());
                App::abort(500);

            }
            $newAccount = $existingAccount;
        }
        $newAccount->save();

        return $newAccount;
    }

    /**
     * @param Account $account
     * @param array   $data
     */
    protected function storeMetadata(Account $account, array $data)
    {
        $metaData = new AccountMeta(
            [
                'account_id' => $account->id,
                'name'       => 'accountRole',
                'data'       => $data['accountRole']
            ]
        );
        if (!$metaData->isValid()) {
            App::abort(500);
        }
        $metaData->save();
    }

    /**
     * @param Account $account
     * @param Account $opposing
     * @param array   $data
     *
     * @return TransactionJournal
     */
    protected function storeInitialBalance(Account $account, Account $opposing, array $data)
    {
        $type            = $data['openingBalance'] < 0 ? 'Withdrawal' : 'Deposit';
        $transactionType = TransactionType::whereType($type)->first();

        $journal = new TransactionJournal(
            [
                'user_id'                 => $data['user'],
                'transaction_type_id'     => $transactionType->id,
                'bill_id'                 => null,
                'transaction_currency_id' => $data['openingBalanceCurrency'],
                'description'             => 'Initial balance for "' . $account->name . '"',
                'completed'               => true,
                'date'                    => $data['openingBalanceDate'],
                'encrypted'               => true
            ]
        );
        if (!$journal->isValid()) {
            App::abort(500);
        }
        $journal->save();


        if ($data['openingBalance'] < 0) {
            $firstAccount  = $opposing;
            $secondAccount = $account;
            $firstAmount   = $data['openingBalance'] * -1;
            $secondAmount  = $data['openingBalance'];
        } else {
            $firstAccount  = $account;
            $secondAccount = $opposing;
            $firstAmount   = $data['openingBalance'];
            $secondAmount  = $data['openingBalance'] * -1;
        }

        // first transaction: from
        $one = new Transaction(
            [
                'account_id'             => $firstAccount->id,
                'transaction_journal_id' => $journal->id,
                'amount'                 => $firstAmount
            ]
        );
        if (!$one->isValid()) {
            App::abort(500);
        }
        $one->save();

        // second transaction: to
        $two = new Transaction(
            [
                'account_id'             => $secondAccount->id,
                'transaction_journal_id' => $journal->id,
                'amount'                 => $secondAmount
            ]
        );
        if (!$two->isValid()) {
            App::abort(500);
        }
        $two->save();

        return $journal;

    }

    /**
     * @param Account $account
     * @param array   $data
     */
    protected function updateMetadata(Account $account, array $data)
    {
        $metaEntries = $account->accountMeta()->get();
        $updated     = false;

        /** @var AccountMeta $entry */
        foreach ($metaEntries as $entry) {
            if ($entry->name == 'accountRole') {
                $entry->data = $data['accountRole'];
                $updated     = true;
                $entry->save();
            }
        }

        if ($updated === false) {
            $metaData = new AccountMeta(
                [
                    'account_id' => $account->id,
                    'name'       => 'accountRole',
                    'data'       => $data['accountRole']
                ]
            );
            if (!$metaData->isValid()) {
                App::abort(500);
            }
            $metaData->save();
        }

    }

    /**
     * @param Account            $account
     * @param TransactionJournal $journal
     * @param array              $data
     *
     * @return TransactionJournal
     */
    protected function updateInitialBalance(Account $account, TransactionJournal $journal, array $data)
    {
        $journal->date = $data['openingBalanceDate'];

        /** @var Transaction $transaction */
        foreach ($journal->transactions()->get() as $transaction) {
            if ($account->id == $transaction->account_id) {
                $transaction->amount = $data['openingBalance'];
                $transaction->save();
            }
            if ($account->id != $transaction->account_id) {
                $transaction->amount = $data['openingBalance'] * -1;
                $transaction->save();
            }
        }

        return $journal;
    }
}
