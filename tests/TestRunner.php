<?php
declare(strict_types=1);

/**
 * Harness de tests minimal, copié tel quel de juridico — générique, sans
 * dépendance de domaine, aucune adaptation nécessaire.
 */
class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            ++$this->passed;
            echo "✔ {$name}\n";
        } catch (Throwable $e) {
            ++$this->failed;
            echo "✘ {$name}\n";
            echo "  ".$e->getMessage()."\n";
        }
    }

    public function report(): int
    {
        echo PHP_EOL;
        echo "====================".PHP_EOL;
        echo "Tests réussis : {$this->passed}".PHP_EOL;
        echo "Tests échoués : {$this->failed}".PHP_EOL;
        echo "====================".PHP_EOL;

        return $this->failed === 0 ? 0 : 1;
    }
}
