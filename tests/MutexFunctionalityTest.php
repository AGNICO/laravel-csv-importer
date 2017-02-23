<?php

namespace RGilyov\CsvImporter\Test;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use RGilyov\CsvImporter\Test\CsvImporters\AsyncCsvImporter;

class MutexFunctionalityTest extends BaseTestCase
{
    use \Illuminate\Foundation\Bus\DispatchesJobs;

    /**
     * @var AsyncCsvImporter
     */
    protected $importer;

    public function setUp()
    {
        parent::setUp();

        $this->importer = (new AsyncCsvImporter())->setFile(__DIR__.'/files/guitars.csv');
        $this->importer->clearSession();

        $this->importer->flushAsyncInfo();

        $this->dispatch(new \RGilyov\CsvImporter\Test\Jobs\TestImportJob());

        /*
         * We need to wait till queue start import, in the separated process
         */
        $this->waitUntilStart();
    }

    public function tearDown()
    {
        /*
         * Make sure the import is finished, before next test
         */
        $this->checkImportFinalResponse();

        parent::tearDown();
    }

    /** @test */
    public function it_can_lock_import_process()
    {
        $initProgress         = $this->importer->getProgress();

        $this->waitUntilEndOfInitialization();

        $progress             = $this->importer->getProgress();

        /*
         * Instead of execution we will get progress information from import which is queued 
         * and running in the another system process
         */
        $preventedRunResponse = $this->importer->run();

        $this->waitUntilFinalStage();

        $finalStageProgress   = $this->importer->getProgress();
        
        $finishedMessage      = $this->checkImportFinalResponse();

        $this->assertEquals('Initialization', $initProgress['data']['message']);
        $this->assertFalse($initProgress['meta']['finished']);
        $this->assertTrue($initProgress['meta']['init']);
        $this->assertTrue($initProgress['meta']['running']);

        $this->assertEquals('Import process is running', $progress['data']['message']);
        $this->assertEquals('integer', gettype($progress['meta']['processed']));
        $this->assertEquals('integer', gettype($progress['meta']['remains']));
        $this->assertEquals('double', gettype($progress['meta']['percentage']));
        $this->assertFalse($progress['meta']['finished']);
        $this->assertFalse($progress['meta']['init']);
        $this->assertTrue($progress['meta']['running']);

        $this->assertEquals('Import process is running', $preventedRunResponse['data']['message']);
        $this->assertEquals('integer', gettype($preventedRunResponse['meta']['processed']));
        $this->assertEquals('integer', gettype($preventedRunResponse['meta']['remains']));
        $this->assertEquals('double', gettype($preventedRunResponse['meta']['percentage']));
        $this->assertFalse($preventedRunResponse['meta']['finished']);
        $this->assertFalse($preventedRunResponse['meta']['init']);
        $this->assertTrue($preventedRunResponse['meta']['running']);

        $this->assertEquals("Final stage", $finalStageProgress['data']['message']);
        $this->assertFalse($finalStageProgress['meta']['finished']);
        $this->assertFalse($finalStageProgress['meta']['init']);
        $this->assertTrue($finalStageProgress['meta']['running']);

        $this->assertEquals(
            "Almost done, please click to the `finish` button to proceed",
            $finishedMessage['data']['message']
        );
        $this->assertTrue($finishedMessage['meta']['finished']);
        $this->assertFalse($finishedMessage['meta']['init']);
        $this->assertFalse($finishedMessage['meta']['running']);
    }
    
    /////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @param int $counter
     * @return mixed
     * @throws \Exception
     */
    protected function checkImportFinalResponse($counter = 0)
    {
        if ($info = Cache::get(AsyncCsvImporter::$cacheInfoKey)) {
            return $info;
        }

        $this->fuse($counter, AsyncCsvImporter::$cacheInfoKey);

        sleep(1);

        return $this->checkImportFinalResponse(++$counter);
    }

    /**
     * @param int $counter
     * @return bool|mixed
     * @throws \Exception
     */
    protected function waitUntilStart($counter = 0)
    {
        if (Cache::get(AsyncCsvImporter::$cacheStartedKey)) {
            return true;
        }

        $this->fuse($counter, AsyncCsvImporter::$cacheStartedKey);

        sleep(1);

        return $this->waitUntilStart(++$counter);
    }

    /**
     * @param int $counter
     * @return bool|mixed
     * @throws \Exception
     */
    protected function waitUntilEndOfInitialization($counter = 0)
    {
        if (Cache::get(AsyncCsvImporter::$cacheInitFinishedKey)) {
            return true;
        }

        $this->fuse($counter, AsyncCsvImporter::$cacheInitFinishedKey);

        sleep(1);

        return $this->waitUntilEndOfInitialization(++$counter);
    }

    /**
     * @param int $counter
     * @return bool|mixed
     * @throws \Exception
     */
    protected function waitUntilFinalStage($counter = 0)
    {
        if (Cache::get(AsyncCsvImporter::$cacheFinalStageStartedKey)) {
            return true;
        }

        $this->fuse($counter, AsyncCsvImporter::$cacheFinalStageStartedKey);

        sleep(1);

        return $this->waitUntilFinalStage(++$counter);
    }

    /**
     * @param $counter
     * @param $key
     * @throws \Exception
     */
    protected function fuse($counter, $key)
    {
        if ($counter > 30) {
            throw new \Exception("Timeout error. Check your queue. Key: " . $key);
        }
    }
}