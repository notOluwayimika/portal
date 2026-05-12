<?php

namespace App\Services;

class PasswordGeneratorService
{
    private const UPPER   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER   = 'abcdefghijklmnopqrstuvwxyz';
    private const DIGITS  = '0123456789';
    private const SYMBOLS = '!@#$%^&*()_+-=[]{}|;:';

    public function generate(int $length = 12): string
    {
        // Guarantee at least one character from each required class
        $password = implode('', [
            $this->pick(self::UPPER, 1),
            $this->pick(self::LOWER, 1),
            $this->pick(self::DIGITS, 1),
            $this->pick(self::SYMBOLS, 1),
        ]);

        $all      = self::UPPER . self::LOWER . self::DIGITS . self::SYMBOLS;
        $password .= $this->pick($all, max(0, $length - 4));

        return str_shuffle($password);
    }

    private function pick(string $chars, int $count): string
    {
        $result = '';
        $max    = strlen($chars) - 1;

        for ($i = 0; $i < $count; $i++) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }
}
