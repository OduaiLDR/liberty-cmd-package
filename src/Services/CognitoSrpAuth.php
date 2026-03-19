<?php

namespace Cmd\Reports\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use phpseclib3\Math\BigInteger;

/**
 * AWS Cognito SRP Authentication for LendingUSA.
 * 
 * Uses phpseclib3 for big number arithmetic required by SRP protocol.
 * Based on AWS Cognito USER_SRP_AUTH flow (RFC 5054).
 */
class CognitoSrpAuth
{
    protected const INFO_BITS = 'Caldera Derived Key';

    protected string $poolId;
    protected string $clientId;
    protected string $region;
    
    // RFC 5054 3072-bit group
    protected const N_HEX = 'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD1' .
        '29024E088A67CC74020BBEA63B139B22514A08798E3404DD' .
        'EF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245' .
        'E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED' .
        'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3D' .
        'C2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F' .
        '83655D23DCA3AD961C62F356208552BB9ED529077096966D' .
        '670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B' .
        'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9' .
        'DE2BCBF6955817183995497CEA956AE515D2261898FA0510' .
        '15728E5A8AAAC42DAD33170D04507A33A85521ABDF1CBA64' .
        'ECFB850458DBEF0A8AEA71575D060C7DB3970F85A6E1E4C7' .
        'ABF5AE8CDB0933D71E8C94E04A25619DCEE3D2261AD2EE6B' .
        'F12FFA06D98A0864D87602733EC86A64521F2B18177B200C' .
        'BBE117577A615D6C770988C0BAD946E208E24FA074E5AB31' .
        '43DB5BFCE0FD108E4B82D120A93AD2CAFFFFFFFFFFFFFFFF';
    protected const G_HEX = '2';

    protected Client $httpClient;
    protected BigInteger $N;
    protected BigInteger $g;
    protected BigInteger $k;
    protected ?BigInteger $a = null;
    protected ?BigInteger $A = null;

    public function __construct()
    {
        $this->poolId = env('COGNITO_POOL_ID', '');
        $this->clientId = env('COGNITO_CLIENT_ID', '');
        $this->region = env('COGNITO_REGION', 'us-west-2');

        if (empty($this->poolId) || empty($this->clientId)) {
            throw new \RuntimeException('COGNITO_POOL_ID and COGNITO_CLIENT_ID must be set in .env');
        }

        $this->httpClient = new Client(['timeout' => 30]);
        $this->N = new BigInteger(self::N_HEX, 16);
        $this->g = new BigInteger(self::G_HEX, 16);
        // k = H(N || g) with proper padding
        $this->k = new BigInteger($this->hexHash('00' . self::N_HEX . '0' . self::G_HEX), 16);
    }

    /**
     * Authenticate with Cognito using SRP and return tokens.
     */
    public function authenticate(string $username, string $password): array
    {
        // Reset state
        $this->a = null;
        $this->A = null;

        // Step 1: InitiateAuth
        $initResponse = $this->cognitoRequest('InitiateAuth', [
            'AuthFlow' => 'USER_SRP_AUTH',
            'ClientId' => $this->clientId,
            'AuthParameters' => [
                'USERNAME' => $username,
                'SRP_A' => $this->largeA()->toHex(),
            ],
        ]);

        if (($initResponse['ChallengeName'] ?? '') !== 'PASSWORD_VERIFIER') {
            throw new \RuntimeException('Unexpected challenge: ' . json_encode($initResponse));
        }

        $params = $initResponse['ChallengeParameters'];
        $userId = $params['USER_ID_FOR_SRP'];
        $saltHex = $params['SALT'];
        $srpBHex = $params['SRP_B'];
        $secretBlock = $params['SECRET_BLOCK'];

        // Compute HKDF key
        $hkdf = $this->getPasswordAuthenticationKey($userId, $password, $srpBHex, $saltHex);

        // Generate timestamp (AWS format)
        $timestamp = $this->getTimestamp();

        // Compute signature
        $poolName = $this->poolName();
        $msg = $poolName . $userId . base64_decode($secretBlock) . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $msg, $hkdf, true));

        // Step 2: RespondToAuthChallenge
        $authResponse = $this->cognitoRequest('RespondToAuthChallenge', [
            'ChallengeName' => 'PASSWORD_VERIFIER',
            'ClientId' => $this->clientId,
            'ChallengeResponses' => [
                'USERNAME' => $userId,
                'PASSWORD_CLAIM_SIGNATURE' => $signature,
                'PASSWORD_CLAIM_SECRET_BLOCK' => $secretBlock,
                'TIMESTAMP' => $timestamp,
            ],
        ]);

        if (!isset($authResponse['AuthenticationResult'])) {
            throw new \RuntimeException('Auth failed: ' . json_encode($authResponse));
        }

        return [
            'IdToken' => $authResponse['AuthenticationResult']['IdToken'] ?? '',
            'AccessToken' => $authResponse['AuthenticationResult']['AccessToken'] ?? '',
            'RefreshToken' => $authResponse['AuthenticationResult']['RefreshToken'] ?? '',
            'ExpiresIn' => $authResponse['AuthenticationResult']['ExpiresIn'] ?? 0,
        ];
    }

    protected function poolName(): string
    {
        return explode('_', $this->poolId)[1];
    }

    protected function smallA(): BigInteger
    {
        if ($this->a === null) {
            $randomHex = bin2hex(random_bytes(128));
            $random = new BigInteger($randomHex, 16);
            $this->a = $random->powMod(new BigInteger(1), $this->N);
        }
        return $this->a;
    }

    protected function largeA(): BigInteger
    {
        if ($this->A === null) {
            $this->A = $this->g->powMod($this->smallA(), $this->N);
            if ($this->A->powMod(new BigInteger(1), $this->N)->equals(new BigInteger(0))) {
                throw new \RuntimeException('A mod N == 0');
            }
        }
        return $this->A;
    }

    protected function getPasswordAuthenticationKey(string $username, string $password, string $srpB, string $salt): string
    {
        $serverB = new BigInteger($srpB, 16);
        
        // u = H(pad(A) || pad(B))
        $u = new BigInteger($this->hexHash($this->padHex($this->largeA()) . $this->padHex($serverB)), 16);
        
        if ($u->equals(new BigInteger(0))) {
            throw new \RuntimeException('U cannot be zero');
        }

        // x = H(salt || H(poolName || username || ":" || password))
        $usernamePassword = $this->poolName() . $username . ':' . $password;
        $usernamePasswordHash = $this->hash($usernamePassword);
        $x = new BigInteger($this->hexHash($this->padHex($salt) . $usernamePasswordHash), 16);

        // S = (B - k * g^x)^(a + u*x) mod N
        $gModPowX = $this->g->modPow($x, $this->N);
        $kgx = $this->k->multiply($gModPowX);
        $diff = $serverB->subtract($kgx);
        
        $exp = $this->smallA()->add($u->multiply($x));
        $S = $diff->modPow($exp, $this->N);

        // HKDF
        return $this->computeHkdf(
            hex2bin($this->padHex($S)),
            hex2bin($this->padHex($u))
        );
    }

    protected function computeHkdf(string $ikm, string $salt): string
    {
        return hash_hkdf('sha256', $ikm, 16, self::INFO_BITS, $salt);
    }

    protected function getTimestamp(): string
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        return $now->format('D M j H:i:s e Y');
    }

    protected function hexHash(string $hexData): string
    {
        $hash = hash('sha256', hex2bin($hexData));
        return str_pad($hash, 64, '0', STR_PAD_LEFT);
    }

    protected function hash(string $data): string
    {
        $hash = hash('sha256', $data);
        return str_pad($hash, 64, '0', STR_PAD_LEFT);
    }

    protected function padHex(BigInteger|string $value): string
    {
        $hex = $value instanceof BigInteger ? $value->toHex() : $value;
        
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        
        if (strlen($hex) > 0 && str_contains('89ABCDEFabcdef', $hex[0])) {
            $hex = '00' . $hex;
        }
        
        return $hex;
    }

    protected function cognitoRequest(string $action, array $body): array
    {
        $url = 'https://cognito-idp.' . $this->region . '.amazonaws.com/';
        
        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Content-Type' => 'application/x-amz-json-1.1',
                    'X-Amz-Target' => 'AWSCognitoIdentityProviderService.' . $action,
                ],
                'json' => $body,
            ]);

            return json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Cognito {$action} failed: " . $e->getMessage());
        }
    }
}
