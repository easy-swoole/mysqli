<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Export;


class Event
{
    // export

    // 前言 注释
    const onWriteFrontNotes = 'onWriteFrontNotes';

    // 数据表结构 循环触发
    const onWriteTableStruct = 'onWriteTableStruct';

    // 单张表数据前的注释 循环触发
    const onBeforeWriteTableDataNotes = 'onBeforeWriteTableDataNotes';

    // 开始导出数据之前
    const onBeforeExportTableData = 'onBeforeExportTableData';

    // 导出数据中 循环触发
    const onExportingTableData = 'onExportingTableData';

    // 导出数据完成 仅表示一个表
    const onAfterExportTableData = 'onAfterExportTableData';

    // 单张表导出后数据的注释 循环触发
    const onAfterWriteTableDataNotes = 'onAfterWriteTableDataNotes';

    // 导出完毕 注释
    const onWriteCompletedNotes = 'onWriteCompletedNotes';

    // import

    // 导入前
    const onBeforeImportTableData = 'onBeforeImportTableData';

    // 导入中 循环触发
    const onImportingTableData = 'onImportingTableData';
    
    // 导入完毕
    const onAfterImportTableData = 'onAfterImportTableData';
}