<?php

namespace Cmd\Reports\Console\Commands;

use Illuminate\Console\Command;
use Cmd\Reports\Services\DBConnector;
use Exception;

class TestDatabaseConnections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:test-connections {--connection=all : Environment to test (plaw, ldr, lt, all)} {--show-key : Show public key information for Snowflake registration} {--sql-server : Also test SQL Server connection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test database connections (Snowflake JWT authentication and SQL Server)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $env = $this->option('connection');
        // Don't pass mode - let DBConnector auto-detect based on credentials
        
        if ($env === 'all') {
            return $this->testAllEnvironments();
        }
        
        return $this->testSingleEnvironment($env);
    }
    
    /**
     * Test a single environment
     */
    private function testSingleEnvironment(string $env, ?string $mode = null): int
    {
        // Let DBConnector auto-detect mode if not provided
        $snowflake = DBConnector::fromEnvironment($env, $mode);
        
        // Get detected mode for display
        $detectedMode = $mode ?: 'auto-detected';
        
        $this->info(str_repeat('=', 50));
        $this->info("Testing {$env} environment ({$detectedMode} mode)...");
        $this->info(str_repeat('=', 50));
        
        try {
            
            // Display connection info
            $info = $snowflake->getConnectionInfo();
            $this->line("Account: {$info['account']}");
            $this->line("User: {$info['user']}");
            $this->line("Database: {$info['database']}");
            $this->line("Schema: {$info['schema']}");
            $this->line("Warehouse: {$info['warehouse']}");
            $this->line("Role: {$info['role']}");
            
            if ($this->option('verbose')) {
                $this->line("Qualified Username: " . strtoupper(explode('.', $info['account'])[0] . '.' . $info['user']));
                $this->line("Audience URL: https://" . strtolower($info['account']) . ".snowflakecomputing.com");
            }
            
            if ($this->option('show-key')) {
                $keyInfo = $snowflake->getPublicKeyForRegistration();
                $this->info('Public Key Information for Snowflake Registration:');
                $this->line("Fingerprint: {$keyInfo['fingerprint']}");
                $this->line("Public Key (clean): " . substr($keyInfo['public_key_clean'], 0, 100) . "...");
                $this->newLine();
                $this->comment('Use this SQL to register the public key in Snowflake:');
                $this->line("ALTER USER {$info['user']} SET RSA_PUBLIC_KEY='{$keyInfo['public_key_clean']}';");
            }
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
            
            // Test query - auto-detect based on configured database
            $this->info("Checking {$info['database']}.{$info['schema']} access...");
            
            // Auto-detect query based on database name
            if ($info['database'] === 'DPP_DATA') {
                // Production database - query actual data table
                $queryResult = $snowflake->query('SELECT COUNT(*) as count FROM DPP_DATA.READER.BUDGET_DATA');
                
                if (!empty($queryResult['data'])) {
                    $count = number_format($queryResult['data'][0]['COUNT']);
                    $this->info("✅ DPP_DATA.READER.BUDGET_DATA: {$count} rows");
                }
            } else {
                // Sandbox database - use simple query
                $queryResult = $snowflake->query('SELECT CURRENT_VERSION() as version, CURRENT_DATABASE() as database, CURRENT_SCHEMA() as schema');
                
                if (!empty($queryResult['data'])) {
                    $data = $queryResult['data'][0];
                    $this->info("✅ Sandbox Environment Access:");
                    $this->line("  Version: {$data['VERSION']}");
                    $this->line("  Database: {$data['DATABASE']}");
                    $this->line("  Schema: {$data['SCHEMA']}");
                }
            }
            
            if (empty($queryResult['data'])) {
                $this->error('❌ No data returned from query');
                return 1;
            }

            // Test SQL Server connection if specifically requested (but not when testing all - handled separately)
            if ($this->option('sql-server') && $this->option('connection') !== 'all') {
                $this->newLine();
                $this->info('🟡 Testing SQL Server connection...');
                
                try {
                    $snowflake->initializeSqlServer($mode);
                    $sqlResult = $snowflake->testSqlServerConnection();
                    
                    if ($sqlResult['success']) {
                        $this->info('✅ SQL Server connected successfully!');
                        $this->line("  Server: {$sqlResult['data']['server']}");
                        $this->line("  Database: {$sqlResult['data']['database']}");
                        $this->line("  Time: {$sqlResult['data']['time']}");
                        
                        // Test table access
                        $sqlServerInfo = $snowflake->getSqlServerInfo();
                        $tableResult = $snowflake->querySqlServer("SELECT COUNT(*) as count FROM {$sqlServerInfo['table']}");
                        
                        if ($tableResult['success']) {
                            $this->info("✅ {$sqlServerInfo['table']} table: {$tableResult['data'][0]['count']} rows");
                        } else {
                            $this->warn("⚠️ Could not access {$sqlServerInfo['table']} table: {$tableResult['error']}");
                        }
                        
                        $this->info('🎉 Both Snowflake and SQL Server connections working!');
                    } else {
                        $this->error("❌ SQL Server connection failed: {$sqlResult['error']}");
                        return 1;
                    }
                } catch (Exception $e) {
                    $this->error("❌ SQL Server error: " . $e->getMessage());
                    return 1;
                }
            }
            
            return 0;
            
        } catch (Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            
            // Provide helpful guidance for configuration errors
            if (strpos($e->getMessage(), 'Missing required') !== false) {
                $this->newLine();
                $this->comment('💡 Configuration Help:');
                $this->line('The configuration is now environment-variable based for production readiness.');
                $this->line('You can either:');
                $this->line('1. Set environment variables in your .env file');
                $this->line('2. Export them in your shell');
                $this->newLine();
                $this->line('Example for ' . strtoupper($env) . ' ' . strtoupper($mode) . ':');
                
                if ($mode === 'sandbox') {
                    $this->line("SNOWFLAKE_{$env}_SANDBOX_ACCOUNT=your-account");
                    $this->line("SNOWFLAKE_{$env}_SANDBOX_USER=your-user");
                    $this->line("SNOWFLAKE_{$env}_SANDBOX_PRIVATE_KEY='-----BEGIN PRIVATE KEY-----...'");
                } else {
                    $this->line("SNOWFLAKE_{$env}_ACCOUNT=your-account");
                    $this->line("SNOWFLAKE_{$env}_USER=your-user");
                    $this->line("SNOWFLAKE_{$env}_PRIVATE_KEY='-----BEGIN PRIVATE KEY-----...'");
                }
            }
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }
    
    /**
     * Test all environments
     */
    private function testAllEnvironments(?string $mode = null): int
    {
        $this->info(str_repeat('=', 50));
        $this->info("Testing ALL environments (auto-detecting mode from credentials)");
        $this->info(str_repeat('=', 50));
        $this->newLine();
        
        $environments = ['plaw', 'ldr', 'lt'];
        $results = [];
        
        foreach ($environments as $env) {
            $result = $this->testSingleEnvironment($env, $mode);
            $results[$env] = $result === 0;
            $this->newLine();
        }
        
        // Test SQL Server when testing all connections
        $this->info(str_repeat('=', 50));
        $this->info('Testing SQL Server connection...');
        $this->info(str_repeat('=', 50));
        
        try {
            $snowflake = DBConnector::fromEnvironment('lt', $mode); // Use LT for SQL Server, let it auto-detect mode
            $snowflake->initializeSqlServer($mode);
            $sqlResult = $snowflake->testSqlServerConnection();
            
            if ($sqlResult['success']) {
                $this->info('✅ SQL Server connected successfully!');
                $this->line("  Server: {$sqlResult['data']['server']}");
                $this->line("  Database: {$sqlResult['data']['database']}");
                $this->line("  Time: {$sqlResult['data']['time']}");
                
                $results['sql_server'] = true;
            } else {
                $this->error("❌ SQL Server connection failed: {$sqlResult['error']}");
                $results['sql_server'] = false;
            }
        } catch (Exception $e) {
            $this->error("❌ SQL Server error: " . $e->getMessage());
            $results['sql_server'] = false;
        }
        
        $this->newLine();
        
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
