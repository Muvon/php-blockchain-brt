<?php
namespace Muvon\Blockchain;
use Muvon\KISS\BlockchainClient;
use Muvon\KISS\JsonRpc;
use BRTNetwork\BRTKeypairs\BRTKeypairs;
use BRTNetwork\BRTAddressCodec\BRTAddressCodec;
use BRTNetwork\BRTLib\Transaction\Sign;

final class BRT extends BlockchainClient {
  protected JsonRpc $Client;
  const EPOCH_OFFSET = 1614556800;

  public function __construct(protected string $url, protected string $user = '', protected string $password = '') {
    $this->Client = JsonRpc::create($url, $user, $password)
      ->setCheckResultFn(function (array $result) {
        if (isset($result['error'])) {
          return 'e_' . $result['error'];
        }
      })
    ;
  }

  /**
   * @return array ['address', 'private']
   */
  public function generateAddress(): array {
    $Keypairs = new BRTKeypairs();
    $seed = $Keypairs->generateSeed();
    $keypair = $Keypairs->deriveKeypair($seed);
    $address = $Keypairs->deriveAddress($keypair['public']);
    return [
      null,
      [
        'address' => $address,
        'public' => $keypair['public'],
        'secret' => [
          'private' => $keypair['private'],
          'seed' => $seed
        ]
      ]
    ];
  }

  public function isAddressValid(string $address): bool {
    $Codec = new BRTAddressCodec;
    return $Codec->isValidClassicAddress($address);
  }

  public function getBlock(int|string $ledger_index, bool $expand = false): array {
    [$err, $ledger] = $this->Client->ledger([
      'ledger_index' => $ledger_index,
      'accounts' => false,
      'transactions' => true,
      'expand' => $expand,
    ]);

    if ($err) {
      return [$err, null];
    }

    if (!$ledger['ledger']['closed']) {
      return ['e_block_not_found', null];
    }

    [$err, $current_index] = $this->getBlockNumber();
    if ($err) {
      return [$err, null];
    }

    $result = [
      'block' => $ledger_index,
      'hash' => $ledger['ledger_hash'],
      'time' => $ledger['ledger']['close_time'] + static::EPOCH_OFFSET,
      'txs' => $expand
        ? array_filter(array_map(function ($tx) use ($ledger, $ledger_index) {
          $tx['ledger_index'] = $ledger_index;
          $tx['date'] = $ledger['ledger']['close_time'];
          $tx['meta'] = &$tx['metaData'];
          return $this->adaptTx($tx, $ledger['ledger_index']);
        }, $ledger['ledger']['transactions']))
        : $ledger['ledger']['transactions'],
      'confirmations' => $current_index - $ledger['ledger_index'],
    ];

    return [null, $result];
  }

  public function getTotalSupply(): string {
    [$err, $ledger] = $this->Client->ledger([
      'ledger_index' => 'closed',
      'accounts' => false,
      'transactions' => false,
      'expand' => false,
    ]);

    if ($err) {
      return '0';
    }

    return strval($ledger['ledger']['total_coins']);
  }

  public function getTx(string $tx): array {
    [$err, $result] = $this->Client->tx([
      'transaction' => $tx,
      'binary' => false,
    ]);

    if ($err) {
      return [$err, null];
    }

    [$err, $ledger_index] = $this->getBlockNumber();
    if ($err) {
      return [$err, null];
    }

    return [null, $this->adaptTx($result, $ledger_index)];
  }

  public function isTxValid(string $hash): bool {
    return strtoupper($hash) === $hash && strlen($hash) === 64;
  }

  public function getAddressBalance(string $address, int $confirmations = 0): array {
    [$err, $info] = $this->Client->account_info([
      'account' => $address
    ]);

    if ($err) {
      return [$err, null];
    }

    return [null, $info['account_data']['Balance']];
  }

  public function getAddressTxs(string $address, int $confirmations = 0, int $since_ts = 0): array {
    [$err, $txs] = $this->Client->account_tx([
      'account' => $address,
      'binary' => false,
      'forward' => false,
      'limit' => 0
    ]);

    if ($err) {
      return [$err, null];
    }

    [$err, $ledger_index] = $this->getBlockNumber();
    if ($err) {
      return [$err, null];
    }

    $result = [];
    // $max_index = sizeof($txs['transactions']) - 1;
    foreach ($txs['transactions'] as $idx => $tx) {
      $adapted_tx = $this->adaptTx($tx['tx'], $ledger_index, $address);

      if ($adapted_tx) {
        $result[$tx['tx']['hash']] = $adapted_tx;
      }
    }
    return [null, $result];
  }

  public function signTx(array $inputs, array $outputs, int|string $fee = 0): array {
    assert(isset($inputs[0]['secret']['seed']));
    assert(isset($inputs[0]['address']));
    [$err, $account_info] = $this->Client->account_info(['account' => $inputs[0]['address']]);
    if ($err) {
      return [$err, null];
    }
    $seed = $inputs[0]['secret']['seed'];
    $sequence = $account_info['account_data']['Sequence'];

    $tx_json = [
      "TransactionType" => "Payment",
      "Account" => $inputs[0]['address'],
      "Destination" => $outputs[0]['address'],
      "Amount" => $outputs[0]['value'],
      "Fee" => $fee,
      "Sequence" => $sequence
    ];

    $Sign = new Sign();
    $tx_signed = $Sign->sign($tx_json, $seed);
    return [null, [
      'raw' => $tx_signed['signedTransaction'],
      'id' => $tx_signed['id'],
    ]];
  }

  public function submitTx(array $tx): array {
    [$err, $result] = $this->Client->submit(['tx_blob' => $tx['raw']]);

    if ($err || !$result['applied']) {
      if ($result['engine_result'] === 'temBAD_SIGNATURE' || $result['engine_result'] === 'tefBAD_AUTH_MASTER') {
        return ['e_bad_signature', $tx['id']];
      }


      if ($result['engine_result'] === 'tefPAST_SEQ') {
        return ['e_bad_sequence', $tx['id']];
      }

      if ($result['engine_result'] === 'temREDUNDANT') {
        return ['e_redundant', $tx['id']];
      }

      return ['e_transaction_send_failed', $tx['id']];
    }

    // When transaction applied but we got error on it
    if ($result['engine_result'] === 'tecUNFUNDED_PAYMENT') {
      return ['e_unfunded_payment', $tx['id']];
    }

    return [null, $result['tx_json']['hash']];
  }

  public function getBlockNumber(): array {
    [$err, $ledger] = $this->Client->ledger_closed();
    if ($err) {
      return [$err, null];
    }

    return [null, $ledger['ledger_index']];
  }

  public function getNetworkFee(): array {
    [$err, $result] = $this->Client->fee();
    if ($err) {
      return [$err, null];
    }
    return [null, $result['drops']['minimum_fee']];
  }

  public function hasMultipleOutputs(): bool {
    return false;
  }

  public function getConfirmations(): int {
    return 1;
  }

  protected function adaptTx(array $tx, int $ledger_index, string $address = null): array|null {
    // We adapt only payment txs and succeed
    if ($tx['TransactionType'] !== 'Payment' || $tx['meta']['TransactionResult'] !== 'tesSUCCESS') {
      return null;
    }
    $value = $tx['Amount'];
    return [
      'hash' => $tx['hash'],
      'value' => $value,
      'time' => $tx['date'] + static::EPOCH_OFFSET,
      'confirmations' => $ledger_index - $tx['ledger_index'],
      'block' => $tx['ledger_index'],
      'fee' => $tx['Fee'],
      'account' => $address ?: null,
      'balance' => $tx['Account'] === $address ? gmp_sub(0, $value) : $value,
      'from' => [$tx['Account']],
      'to' => [
        [
          'address' => $tx['Destination'],
          'value' => $value,
        ],
      ],
    ];
  }
}
