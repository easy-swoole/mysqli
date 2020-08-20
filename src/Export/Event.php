<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Export;


class Event
{
    // export
    // 前言
    const onWriteFront = 'onWriteFront';
    // 数据表结构
    const onWriteTableStruct = 'onWriteTableStruct';
    // 开始导出数据之前
    const onBeforeExportTableData = 'onBeforeExportTableData';
    // 导出数据中 循环触发
    const onExportingTableData = 'onExportingTableData';
    // 导出数据完成 仅表示一个表
    const onAfterExportTableData = 'onAfterExportTableData';
    // 导出完毕
    const onWriteCompleted = 'onWriteCompleted';

    // import
    // 导入前
    const onBeforeImportTableData = 'onBeforeImportTableData';
    // 导入中 循环触发
    const onImportingTableData = 'onImportingTableData';
    // 导入完毕
    const onAfterImportTableData = 'onAfterImportTableData';
}