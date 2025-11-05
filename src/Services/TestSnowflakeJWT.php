<?php

namespace Packages\Cmd\Reports\ScheduledReports;

use Illuminate\Console\Command;
use Packages\Cmd\Reports\Services\SnowflakeConnector;
use Exception;

/**
 * Test Snowflake JWT Connection
 * 
 * Tests the JWT-based Snowflake connection for all environments
 */
class TestSnowflakeJWT extends Command
{
    protected $signature = 'snowflake:test-jwt {--connection=plaw : Environment to test (plaw, ldr, lt, all)}';
    
    protected $description = 'Test Snowflake JWT authentication connection';

    public function handle()
    {
        $env = $this->option('connection');
        
        if ($env === 'all') {
            return $this->testAllEnvironments();
        }
        
        return $this->testSingleEnvironment($env);
    }
    
    /**
     * Test a single environment
     */
    private function testSingleEnvironment(string $env): int
    {
        $this->info(str_repeat('=', 50));
        $this->info("Testing {$env} environment...");
        $this->info(str_repeat('=', 50));
        
        try {
            $snowflake = SnowflakeConnector::fromEnvironment($env);
            
            // Display connection info
            $info = $snowflake->getConnectionInfo();
            $this->line("Account: {$info['account']}");
            $this->line("User: {$info['user']}");
            $this->line("Database: {$info['database']}");
            $this->line("Schema: {$info['schema']}");
            $this->line("Warehouse: {$info['warehouse']}");
            $this->line("Role: {$info['role']}");
            $this->newLine();
            
            // Test connection
            $this->info('Testing connection...');
            $result = $snowflake->testConnection();
            
            if (!$result['success']) {
                $this->error('❌ Connection failed: ' . $result['error']);
                return 1;
            }
            
            $this->info('✅ Connected successfully!');
            $this->line("  Account: {$info['account']}");
            $this->line("  User: {$result['data']['USER']}");
            $this->line("  Role: {$result['data']['ROLE']}");
            $this->newLine();
            
            // Test query
            $this->info('Checking DPP_DATA access...');
            $queryResult = $snowflake->query('SELECT COUNT(*) as count FROM DPP_DATA.READER.BUDGET_DATA');
            
            if (empty($queryResult['data'])) {
                $this->error('❌ No data returned from query');
                return 1;
            }
            
            $count = number_format($queryResult['data'][0]['COUNT']);
            $this->info("✅ DPP_DATA.READER.BUDGET_DATA: {$count} rows");
            
            return 0;
            
        } catch (Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }
    
    /**
     * Test all environments
     */
    private function testAllEnvironments(): int
    {
        $this->info(str_repeat('=', 50));
        $this->info('Testing ALL environments');
        $this->info(str_repeat('=', 50));
        $this->newLine();
        
        $environments = ['plaw', 'ldr', 'lt'];
        $results = [];
        
        foreach ($environments as $env) {
            $result = $this->testSingleEnvironment($env);
            $results[$env] = $result === 0;
            $this->newLine();
        }
        
        // Summary
        $this->info(str_repeat('=', 50));
        $this->info('TEST SUMMARY');
        $this->info(str_repeat('=', 50));
        
        $successCount = 0;
        foreach ($results as $env => $success) {
            $status = $success ? '✅ SUCCESS' : '❌ FAILED';
            $this->line(strtoupper($env) . ': ' . $status);
            if ($success) {
                $successCount++;
            }
        }
        
        $this->newLine();
        $this->info("Total: {$successCount}/" . count($environments) . " environments connected");
        
        return $successCount === count($environments) ? 0 : 1;
    }
}
