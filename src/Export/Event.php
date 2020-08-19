<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Export;


class Event
{
    // export
    const onBeforeExportTableData = 'onBeforeExportTableData';
    const onExportingTableData = 'onExportingTableData';
    const onAfterExportTableData = 'onAfterExportTableData';

    // import
    const onBeforeImportTableData = 'onBeforeImportTableData';
    const onImportingTableData = 'onImportingTableData';
    const onAfterImportTableData = 'onAfterImportTableData';
}