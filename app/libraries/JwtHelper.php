<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtHelper
{
    const ACCESS_TTL = 900; // 15 minutes

    public static function secret()
    {
        $secret = $_ENV['JWT_SECRET']
            ?? $_SERVER['JWT_SECRET']
            ?? getenv('JWT_SECRET')
            ?? '';

        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET is missing or shorter than 32 characters.');
        }

        return $secret;
    }
    public static function issueAccessToken(array $user)
    {
        $now = time();

        return JWT::encode([
            'iss'  => 'hotel-reservation-api',
            'sub'  => (string) $user['id'],
            'role' => $user['role'],
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + self::ACCESS_TTL,
            'jti'  => bin2hex(random_bytes(16)),
        ], self::secret(), 'HS256');
    }

    public static function decodeAccessToken($token)
    {
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), 'HS256'));
            return (array) $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function bearerToken()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }
}