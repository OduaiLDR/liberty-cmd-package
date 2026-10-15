<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices\GenerateLendingTowerInvoices;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Config\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Fake mailer: records sends instead of calling Graph. */
final class FakeMailer extends EmailSenderService
{
    public array $sent = [];
    public bool $ok = true;

    public function __construct()
    {
    }

    public function sendMailHtml(...$args): bool
    {
        $this->sent[] = ['test' => true] + $args;

        return $this->ok;
    }

    public function sendMailUsingTblReportsHtml(...$args): bool
    {
        $this->sent[] = $args;

        return $this->ok;
    }
}

/** The real command with only the mailer swapped for the fake. */
final class LendingTowerUnderTest extends GenerateLendingTowerInvoices
{
    public function __construct(public FakeMailer $mail)
    {
        parent::__construct();
    }

    /** dompdf needs the GD extension to draw the logo; the tests do not care about the picture. */
    protected function logoDataUri(): ?string
    {
        return null;
    }

    protected function mailer(): EmailSenderService
    {
        return $this->mail;
    }
}

/** Boots just enough Laravel (local disk in a temp dir, null logger) to run deliver() for real, with a fake mailer. */
trait LendingTowerHarness
{
    private string $root;
    private $savedFacade;

    protected function bootHarness(): void
    {
        $this->root = sys_get_temp_dir() . '/lt-registry-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $container = new Container();
        $container->instance('config', new Repository(['filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $this->root]]]]));
        $container->instance('filesystem', new FilesystemManager($container));
        $container->instance('log', new NullLogger());
        $this->savedFacade = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function shutHarness(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->savedFacade);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    protected function command(array $options = []): LendingTowerUnderTest
    {
        $cmd = new LendingTowerUnderTest(new FakeMailer());
        $cmd->setInput(new ArrayInput($options, $cmd->getDefinition()));
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), $this->out = new BufferedOutput()));

        return $cmd;
    }

    protected function call(object $cmd, string $method, array $args): mixed
    {
        return (new ReflectionMethod(GenerateLendingTowerInvoices::class, $method))->invoke($cmd, ...$args);
    }

    /** Live delivery of the September 2026 invoices, as the scheduler would. @return bool all sent */
    protected function deliverLive(LendingTowerUnderTest $cmd, array $companies = ['PLAW'], bool $live = true, string $testTo = ''): bool
    {
        $period = $this->call($cmd, 'periodDetails', ['2026-09-01', '2026-10-01']);
        $invoices = [];
        if (in_array('PLAW', $companies, true)) {
            $invoices['PLAW'] = $this->call($cmd, 'summarizeProgressLaw', [[
                ['CONTACT_ID' => '001', 'CLIENT' => 'Fake One', 'STATE' => 'CO', 'STATUS_LOCAL' => '2026-09-03 05:00:00', 'BILLABLE_STATUS' => 'Contract Sent', 'CURRENT_STATUS' => 'ProLaw Enrolled'],
                ['CONTACT_ID' => '002', 'CLIENT' => 'Fake Two', 'STATE' => 'OH', 'STATUS_LOCAL' => '2026-09-04 05:00:00', 'BILLABLE_STATUS' => 'Contract Sent', 'CURRENT_STATUS' => 'ProLaw Enrolled'],
            ]]);
        }
        if (in_array('LDR', $companies, true)) {
            $invoices['LDR'] = $this->call($cmd, 'summarizeLdr', [[
                ['LLG_ID' => '0001', 'Client' => 'Fake Client', 'First_Payment_Cleared_Date' => '2026-09-03', 'Debt_Source' => 'Sold_Debt', 'Debt_Basis' => '10000.00'],
            ], [], '2026-09-01', '2026-10-01']);
        }
        $azure = (new \ReflectionClass(DBConnector::class))->newInstanceWithoutConstructor();

        return $this->call($cmd, 'deliver', [$invoices, $period, $azure, '', $testTo, $live]);
    }

    protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return \Illuminate\Support\Facades\Storage::disk('local');
    }
}
