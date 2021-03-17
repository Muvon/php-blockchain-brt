<?php
namespace Muvon\Blockchain;
use Muvon\KISS\BlockchainClient;
use Muvon\KISS\RequestTrait;
use BRTNetwork\BRTKeypairs\BRTKeypairs;
use BRTNetwork\BRTAddressCodec\BRTAddressCodec;
use BRTNetwork\BRTLib\Transaction\Sign;

final class BRT extends BlockchainClient {
  use RequestTrait;
  const EPOCH_OFFSET = 1614556800;

  public function __construct(protected string $url, protected string $user = '', protected string $password = '') {}

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

  public function getBlock(int|string $ledger_index): array {
    $ledger = $this->sendAPIRequest('ledger', [
      'ledger_index' => $ledger_index,
      'accounts' => false,
      'transactions' => true,
      'expand' => false,
    ]);

    if (false === $ledger) {
      return ['e_request_failed', null];
    }

    [$err, $current_index] = $this->getBlockNumber();
    if ($err) {
      return [$err, null];
    }

    $result = [
      'block' => $ledger['ledger_index'],
      'hash' => $ledger['ledger']['hash'],
      'time' => $ledger['ledger']['close_time'] + static::EPOCH_OFFSET,
      'txs' => $ledger['ledger']['transactions'],
      'confirmations' => $current_index - $ledger['ledger_index'],
    ];

    return [null, $result];
  }

  public function getTotalSupply(): string {
    $ledger = $this->sendAPIRequest('ledger', [
      'ledger_index' => 'closed',
      'accounts' => false,
      'transactions' => false,
      'expand' => false,
    ]);

    if (false === $ledger) {
      return ['e_request_failed', null];
    }

    return strval($ledger['ledger']['total_coins']);
  }

  public function getTx(string $tx): array {
    $result = $this->sendAPIRequest('tx', [
      'transaction' => $tx,
      'binary' => false,
    ]);

    if (false === $result) {
      return ['e_request_failed', null];
    }

    [$err, $ledger_index] = $this->getBlockNumber();
    if ($err) {
      return ['e_request_failed', null];
    }

    return [null, $this->adaptTx($result, $ledger_index)];
  }

  public function isTxValid(string $hash): bool {
    return strtoupper($hash) === $hash && strlen($hash) === 64;
  }

  public function getAddressBalance(string $address, int $confirmations = 0): array {
    $info = $this->sendAPIRequest('account_info', [
      'account' => $address
    ]);

    if (false === $info) {
      return ['e_request_failed', null];
    }

    return [null, $info['account_data']['Balance']];
  }

  public function getAddressTxs(string $address, int $confirmations = 0, int $since_ts = 0): array {
    $txs = $this->sendAPIRequest('account_tx', [
      'account' => $address,
      'binary' => false,
      'forward' => false,
      'limit' => 0
    ]);
    if (false === $txs) {
      return ['e_request_failed', null];
    }

    [$err, $ledger_index] = $this->getBlockNumber();
    if ($err) {
      return [$err, null];
    }

    $result = [];
    // $max_index = sizeof($txs['transactions']) - 1;
    foreach ($txs['transactions'] as $idx => $tx) {
      // If this transaction is failed // skip
      if ($tx['tx']['TransactionType'] !== 'Payment' || $tx['meta']['TransactionResult'] !== 'tesSUCCESS' || is_array($tx['tx']['Amount'])) {
        continue;
      }

      $result[$tx['tx']['hash']] = $this->adaptTx($tx['tx'], $ledger_index, $address);
    }
    return [null, $result];
  }

  public function signTx(array $inputs, array $outputs, int|string $fee = 0): array {
    assert(isset($inputs[0]['secret']['seed']));
    assert(isset($inputs[0]['address']));
    $account_info = $this->sendAPIRequest('account_info', ['account' => $inputs[0]['address']]);
    if (false === $account_info || !isset($account_info['account_data'])) {
      return ['e_sequence_undefined', null];
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
    $result = $this->sendAPIRequest('submit', ['tx_blob' => $tx['raw']]);

    if (false === $result || !$result['applied']) {
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
    $ledger = $this->sendAPIRequest('ledger_current', [], 'POST');
    if (false === $ledger) {
      return ['e_request_failed', null];
    }
    return [null, $ledger['ledger_current_index']];
  }

  public function getNetworkFee(): array {
    $result = $this->sendAPIRequest('fee', [], 'POST');
    if (false === $result) {
      return ['e_request_failed', null];
    }
    return [null, $result['drops']['minimum_fee']];
  }

  protected function sendAPIRequest(string $path, array $payload = []) {
    if ($this->user && $this->password) {
      $payload['username'] = $this->user;
      $payload['password'] = $this->password;
    }
    list($req_err, $response) = $this->request(
      $this->url,
      [
        'method' => $path,
        'params' => $payload ? [$payload] : null
      ],
      'POST'
    );
    if ($req_err) {
      return false;
    }

    $result = $response['result'];
    if ($result['status'] === 'error') {
      return false;
    }

    return $result;
  }

  public function hasMultipleOutputs(): bool {
    return false;
  }

  public function getConfirmations(): int {
    return 1;
  }

  protected function adaptTx(array $tx, int $ledger_index, string $address = null): array{
    return [
      'hash' => $tx['hash'],
      'value' => $tx['Amount'],
      'time' => $tx['date'] + static::EPOCH_OFFSET,
      'confirmations' => $ledger_index - $tx['ledger_index'],
      'block' => $tx['ledger_index'],
      'fee' => $tx['Fee'],
      'account' => $address ?: null,
      'balance' => $tx['Account'] === $address ? gmp_sub(0, $tx['Amount']) : $tx['Amount'],
      'from' => [$tx['Account']],
      'to' => [
        [
          'address' => $tx['Destination'],
          'value' => $tx['Amount'],
        ],
      ],
    ];
  }
}
