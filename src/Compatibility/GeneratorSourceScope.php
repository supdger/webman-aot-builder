<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

/** Method ownership of the fixed generator's local state and switch conversions. */
final class GeneratorSourceScope
{
    /** @var array<string,list<array{string,string,?list<string>}>> */
    private const RULES = [
        'vendor/topthink/think-orm/src/db/BaseQuery.php' => [
            [
                '["if","(","empty","(","$this","->","options","[","\'where\'","]",")","&&","empty","(","$this","->","options","[","\'scope\'","]",")","&&","empty","(","$this","->","options","[","\'order\'","]",")","&&","empty","(","$this","->","options","[","\'sort\'","]",")",")","{"]',
                '["$resultState","=","new","\\\\stdClass","(",")",";","if","(","empty","(","$this","->","options","[","\'where\'","]",")","&&","empty","(","$this","->","options","[","\'scope\'","]",")","&&","empty","(","$this","->","options","[","\'order\'","]",")","&&","empty","(","$this","->","options","[","\'sort\'","]",")",")","{"]',
                ['find'],
            ],
            [
                '["$result","=","[","]",";"]',
                '["$resultState","->","value","=","[","]",";"]',
                ['find'],
            ],
            [
                '["$result","=","$this","->","connection","->","find","(","$this",")",";"]',
                '["$resultState","->","value","=","$this","->","connection","->","find","(","$this",")",";"]',
                ['find'],
            ],
            [
                '["if","(","empty","(","$result",")",")","{"]',
                '["if","(","empty","(","$resultState","->","value",")",")","{"]',
                ['find'],
            ],
            [
                '["$this","->","resultToModel","(","$result",")",";"]',
                '["$this","->","resultToModel","(","$resultState","->","value",")",";"]',
                ['find'],
            ],
            [
                '["$this","->","result","(","$result",")",";"]',
                '["$this","->","result","(","$resultState","->","value",")",";"]',
                ['find'],
            ],
            [
                '["return","$result",";","}"]',
                '["return","$resultState","->","value",";","}"]',
                ['find'],
            ],
            [
                '["protected","function","tableStr","(","string","$table",")",":","array","|","string","{"]',
                '["protected","function","tableStr","(","string","$table",")",":","array","|","string","{","$tableState","=","new","\\\\stdClass","(",")",";","$tableState","->","value","=","$table",";"]',
                ['tableStr'],
            ],
            [
                '["$table","=","[","]",";"]',
                '["$tableState","->","value","=","[","]",";"]',
                ['tableStr'],
            ],
            [
                '["$table","[","$item","]"]',
                '["$tableState","->","value","[","$item","]"]',
                ['tableStr'],
            ],
            [
                '["$table","[","]"]',
                '["$tableState","->","value","[","]"]',
                ['tableStr'],
            ],
            [
                '["$tableState","->","value","[","]","=","$val",";"]',
                '["$table","[","]","=","$val",";"]',
                [],
            ],
            [
                '["return","$table",";","}"]',
                '["return","$tableState","->","value",";","}"]',
                ['tableStr'],
            ],
        ],
        'vendor/topthink/think-orm/src/db/PDOConnection.php' => [
            [
                '["public","function","getTableInfo","(","array","|","string","$tableName",",","string","$fetch","=","\'\'",")"]',
                '["public","function","getTableInfo","(","mixed","$tableName",",","string","$fetch","=","\'\'",")"]',
                ['getTableInfo'],
            ],
            [
                '["public","function","getFieldsType","(","$tableName",",","?","string","$field","=","null",")"]',
                '["public","function","getFieldsType","(","mixed","$tableName",",","?","string","$field","=","null",")"]',
                ['getFieldsType'],
            ],
            [
                '["$pk","=","count","(","$pk",")",">","1","?","$pk",":","$pk","[","0","]",";","$info","[","\'_pk\'","]","=","$pk",";"]',
                '["$info","[","\'_pk\'","]","=","count","(","$pk",")",">","1","?","$pk",":","$pk","[","0","]",";"]',
                ['getTableInfo'],
            ],
            [
                '["protected","function","autoInsIDType","(","BaseQuery","$query",",","string","$insertId",")","{"]',
                '["protected","function","autoInsIDType","(","BaseQuery","$query",",","string","$insertId",")","{","$insertIdState","=","new","\\\\stdClass","(",")",";","$insertIdState","->","value","=","$insertId",";"]',
                ['autoInsIDType'],
            ],
            [
                '["$insertId","=","(int)","$insertId",";"]',
                '["$insertIdState","->","value","=","(int)","$insertId",";"]',
                ['autoInsIDType'],
            ],
            [
                '["$insertId","=","(float)","$insertId",";"]',
                '["$insertIdState","->","value","=","(float)","$insertId",";"]',
                ['autoInsIDType'],
            ],
            [
                '["return","$insertId",";"]',
                '["return","$insertIdState","->","value",";"]',
                ['autoInsIDType'],
            ],
            [
                '["$dbMaster","=","false",";"]',
                '["$dbMasterState","=","new","\\\\stdClass","(",")",";","$dbMasterState","->","value","=","false",";"]',
                ['multiConnect'],
            ],
            [
                '["$dbMaster","=","[","]",";"]',
                '["$dbMasterState","->","value","=","[","]",";"]',
                ['multiConnect'],
            ],
            [
                '["$dbMaster","[","$name","]","=","$config","[","$name","]","[","$m","]","??","$config","[","$name","]","[","0","]",";"]',
                '["$dbMasterState","->","value","[","$name","]","=","$config","[","$name","]","[","$m","]","??","$config","[","$name","]","[","0","]",";"]',
                ['multiConnect'],
            ],
            [
                '["return","$this","->","connect","(","$dbConfig",",","$r",",","$r","==","$m","?","false",":","$dbMaster",")",";"]',
                '["return","$this","->","connect","(","$dbConfig",",","$r",",","$r","==","$m","?","false",":","$dbMasterState","->","value",")",";"]',
                ['multiConnect'],
            ],
        ],
        'vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php' => [
            [
                '["private","function","getInsertStatement","(","#[","\\\\SensitiveParameter","]","string","$sessionId",",","string","$sessionData",",","int","$maxlifetime",")",":","\\\\PDOStatement","{"]',
                '["private","function","getInsertStatement","(","#[","\\\\SensitiveParameter","]","string","$sessionId",",","string","$sessionData",",","int","$maxlifetime",")",":","\\\\PDOStatement","{","$dataHolder","=","new","\\\\stdClass","(",")",";"]',
                ['getInsertStatement'],
            ],
            [
                '["private","function","getUpdateStatement","(","#[","\\\\SensitiveParameter","]","string","$sessionId",",","string","$sessionData",",","int","$maxlifetime",")",":","\\\\PDOStatement","{"]',
                '["private","function","getUpdateStatement","(","#[","\\\\SensitiveParameter","]","string","$sessionId",",","string","$sessionData",",","int","$maxlifetime",")",":","\\\\PDOStatement","{","$dataHolder","=","new","\\\\stdClass","(",")",";"]',
                ['getUpdateStatement'],
            ],
            [
                '["$data","=","fopen","(","\'php://memory\'",",","\'r+\'",")",";"]',
                '["$dataHolder","->","value","=","fopen","(","\'php://memory\'",",","\'r+\'",")",";"]',
                ['getInsertStatement', 'getUpdateStatement'],
            ],
            [
                '["fwrite","(","$data",",","$sessionData",")",";"]',
                '["fwrite","(","$dataHolder","->","value",",","$sessionData",")",";"]',
                ['getInsertStatement', 'getUpdateStatement'],
            ],
            [
                '["rewind","(","$data",")",";"]',
                '["rewind","(","$dataHolder","->","value",")",";"]',
                ['getInsertStatement', 'getUpdateStatement'],
            ],
            [
                '["$data","=","$sessionData",";"]',
                '["$dataHolder","->","value","=","$sessionData",";"]',
                ['getInsertStatement', 'getUpdateStatement'],
            ],
            [
                '["$stmt","->","bindParam","(","\':data\'",",","$data",",","\\\\PDO","::","PARAM_LOB",")",";"]',
                '["$stmt","->","bindParam","(","\':data\'",",","$dataHolder","->","value",",","\\\\PDO","::","PARAM_LOB",")",";"]',
                ['getInsertStatement', 'getUpdateStatement'],
            ],
            [
                '["case","\'pgsql\'",":"]',
                '["if","(","isset","(","$params","[","\'host\'","]",")","&&","\'\'","!==","$params","[","\'host\'","]",")","{","$dsn",".=","\'host=\'",".","$params","[","\'host\'","]",".","\';\'",";","}","if","(","isset","(","$params","[","\'port\'","]",")","&&","\'\'","!==","$params","[","\'port\'","]",")","{","$dsn",".=","\'port=\'",".","$params","[","\'port\'","]",".","\';\'",";","}","if","(","isset","(","$params","[","\'path\'","]",")",")","{","$dbName","=","substr","(","$params","[","\'path\'","]",",","1",")",";","$dsn",".=","\'dbname=\'",".","$dbName",".","\';\'",";","}","return","$dsn",";","case","\'pgsql\'",":"]',
                ['buildDsnFromUrl'],
            ],
        ],
        'vendor/symfony/http-foundation/Request.php' => [
            [
                '["if","(","$this","->","isFromTrustedProxy","(",")","&&","$host","=","$this","->","getTrustedValues","(","self","::","HEADER_X_FORWARDED_HOST",")",")","{","$host","=","$host","[","0","]",";"]',
                '["if","(","$this","->","isFromTrustedProxy","(",")","&&","$forwardedHosts","=","$this","->","getTrustedValues","(","self","::","HEADER_X_FORWARDED_HOST",")",")","{","$host","=","$forwardedHosts","[","0","]",";"]',
                ['getHost'],
            ],
            [
                '["public","function","getFormat","(","?","string","$mimeType",")",":","?","string","{","$subtypeFallback","=","2","<=","\\\\func_num_args","(",")","?","func_get_arg","(","1",")",":","false",";"]',
                '["public","function","getFormat","(","?","string","$mimeType",",","bool","$subtypeFallback","=","false",")",":","?","string","{"]',
                ['getFormat'],
            ],
            [
                '[]',
                '["public","static","function","resetFormatsForAot","(",")",":","void","{","self","::","$formats","=","null",";","}"]',
                null,
            ],
            [
                '["$_REQUEST","=","[","[","]","]",";","foreach","(","str_split","(","$requestOrder",")","as","$order",")","{","$_REQUEST","[","]","=","$request","[","$order","]",";","}","$_REQUEST","=","array_merge","(","...","$_REQUEST",")",";"]',
                '["$requestParts","=","[","[","]","]",";","foreach","(","str_split","(","$requestOrder",")","as","$order",")","{","$requestParts","[","]","=","$request","[","$order","]",";","}","$_REQUEST","=","array_merge","(","...","$requestParts",")",";"]',
                ['overrideGlobals'],
            ],
            [
                '["case","\'PATCH\'",":"]',
                '["$request","=","$parameters",";","$query","=","[","]",";","break",";","case","\'PATCH\'",":"]',
                ['create'],
            ],
        ],
        'vendor/vlucas/phpdotenv/src/Parser/EntryParser.php' => [
            [
                '["}","case","self","::"]',
                '["}","throw","new","\\\\Error","(","\'Unreachable parser state.\'",")",";","case","self","::"]',
                ['processToken'],
            ],
        ],
        'vendor/topthink/think-orm/src/model/concern/TimeStamp.php' => [
            [
                '["$value","=","$this","->","formatDateTime","(","\'Y-m-d H:i:s.u\'",")",";"]',
                '["return","$this","->","formatDateTime","(","\'Y-m-d H:i:s.u\'",")",";"]',
                ['getTimeTypeValue'],
            ],
            [
                '["$value","=","$obj","->","__toString","(",")",";"]',
                '["return","$obj","->","__toString","(",")",";"]',
                ['getTimeTypeValue'],
            ],
            [
                '["}","}","}"]',
                '["}","}","break",";","}"]',
                ['getTimeTypeValue'],
            ],
        ],
        'vendor/phpmailer/phpmailer/src/PHPMailer.php' => [
            [
                '["$encoding","=","false",";"]',
                '["$encoding","=","\'\'",";"]',
                ['encodeHeader'],
            ],
            [
                '["if","(","$this","->","has8bitChars","(","substr","(","$address",",","++","$pos",")",")",")","{"]',
                '["if","(","$this","->","has8bitChars","(","substr","(","$address",",","$pos","+","1",")",")",")","{"]',
                ['addOrEnqueueAnAddress'],
            ],
            [
                '["case","\'comment\'",":","$matchcount","=","preg_match_all","(","\'/[()\\"]/\'",",","$str",",","$matches",")",";","case","\'text\'",":","default",":","$matchcount","+=","preg_match_all","(","\'/[\\\\000-\\\\010\\\\013\\\\014\\\\016-\\\\037\\\\177-\\\\377]/\'",",","$str",",","$matches",")",";","break",";"]',
                '["case","\'comment\'",":","$matchcount","=","preg_match_all","(","\'/[()\\"]/\'",",","$str",",","$matches",")",";","$matchcount","+=","preg_match_all","(","\'/[\\\\000-\\\\010\\\\013\\\\014\\\\016-\\\\037\\\\177-\\\\377]/\'",",","$str",",","$matches",")",";","break",";","case","\'text\'",":","default",":","$matchcount","+=","preg_match_all","(","\'/[\\\\000-\\\\010\\\\013\\\\014\\\\016-\\\\037\\\\177-\\\\377]/\'",",","$str",",","$matches",")",";","break",";"]',
                ['encodeHeader'],
            ],
            [
                '["case","\'comment\'",":","$pattern","=","\'\\\\(\\\\)\\"\'",";","case","\'text\'",":","default",":","$pattern","=","\'\\\\000-\\\\011\\\\013\\\\014\\\\016-\\\\037\\\\075\\\\077\\\\137\\\\177-\\\\377\'",".","$pattern",";","break",";"]',
                '["case","\'comment\'",":","$pattern","=","\'\\\\000-\\\\011\\\\013\\\\014\\\\016-\\\\037\\\\075\\\\077\\\\137\\\\177-\\\\377\\\\(\\\\)\\"\'",";","break",";","case","\'text\'",":","default",":","$pattern","=","\'\\\\000-\\\\011\\\\013\\\\014\\\\016-\\\\037\\\\075\\\\077\\\\137\\\\177-\\\\377\'",".","$pattern",";","break",";"]',
                ['encodeQ'],
            ],
            [
                '["\\"\\\\n\\"",";","}"]',
                '["\\"\\\\n\\"",";","break",";","}"]',
                ['edebug'],
            ],
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Date.php' => [
            [
                '["$result","->","$name","=","$value",";"]',
                '["$result","->","$name","=","$value",";","break",";"]',
                ['set'],
            ],
            [
                '["}","catch","(","UnknownUnitException",")","{","}","default",":","$macro","=","$this","->","getLocalMacro","(","\'get\'",".","ucfirst","(","$name",")",")",";"]',
                '["}","catch","(","UnknownUnitException",")","{","}","$fallbackMacro","=","$this","->","getLocalMacro","(","\'get\'",".","ucfirst","(","$name",")",")",";","if","(","$fallbackMacro",")","{","return","$this","->","executeCallableWithContext","(","$fallbackMacro",")",";","}","throw","new","UnknownGetterException","(","$name",")",";","default",":","$macro","=","$this","->","getLocalMacro","(","\'get\'",".","ucfirst","(","$name",")",")",";"]',
                ['get'],
            ],
            [
                '["$value","=","$operator","===","\'Of\'"]',
                '["$unitValue","=","$operator","===","\'Of\'"]',
                ['get'],
            ],
            [
                '["return","(int)","$value",";"]',
                '["return","(int)","$unitValue",";"]',
                ['get'],
            ],
            [
                '["$result","=","$result","->","subSecond","(",")",";"]',
                '["$result","=","$result","->","addUnit","(","\'second\'",",","-","1",")",";"]',
                ['set'],
            ],
            [
                '["$result","=","$result","->","addSecond","(",")",";"]',
                '["$result","=","$result","->","addUnit","(","\'second\'",",","1",")",";"]',
                ['set'],
            ],
            [
                '["$result","=","$result","->","addDays","(","$value","-","$this","->","dayOfYear",")",";"]',
                '["$result","=","$result","->","addUnit","(","\'day\'",",","$value","-","$this","->","dayOfYear",")",";"]',
                ['set'],
            ],
            [
                '["$result","=","$result","->","addDays","(","$value","-","$this","->","dayOfWeek",")",";"]',
                '["$result","=","$result","->","addUnit","(","\'day\'",",","$value","-","$this","->","dayOfWeek",")",";"]',
                ['set'],
            ],
            [
                '["$result","=","$result","->","addDays","(","$value","-","$this","->","dayOfWeekIso",")",";"]',
                '["$result","=","$result","->","addUnit","(","\'day\'",",","$value","-","$this","->","dayOfWeekIso",")",";"]',
                ['set'],
            ],
            [
                '["$this","->","addDays","("]',
                '["$this","->","addUnit","(","\'day\'",","]',
                ['*'],
            ],
            [
                '["$boundMacro","=","@","$macro","->","bindTo","(","$this",",","static","::","class",")","?",":","@","$macro","->","bindTo","(","null",",","static","::","class",")",";"]',
                '["throw","new","\\\\RuntimeException","(","\'AOT Carbon closure macro binding is not supported.\'",")",";"]',
                ['executeCallable'],
            ],
            [
                '["$boundMacro","=","@","Closure","::","bind","(","$macro",",","null",",","static","::","class",")",";"]',
                '["throw","new","\\\\RuntimeException","(","\'AOT Carbon static closure macro binding is not supported.\'",")",";"]',
                ['executeStaticCallable'],
            ],
            [
                '["return","\\\\call_user_func_array","(","$boundMacro","?",":","$macro",",","$parameters",")",";"]',
                '["return","\\\\call_user_func_array","(","$macro",",","$parameters",")",";"]',
                ['executeCallable', 'executeStaticCallable'],
            ],
            [
                '["$","$name","=","self","::","monthToInt","(","$value",",","$name",")",";"]',
                '["$normalizedValue","=","self","::","monthToInt","(","$value",",","$name",")",";","switch","(","$name",")","{","case","\'year\'",":","$year","=","$normalizedValue",";","break",";","case","\'month\'",":","$month","=","$normalizedValue",";","break",";","case","\'day\'",":","$day","=","$normalizedValue",";","break",";","case","\'hour\'",":","$hour","=","$normalizedValue",";","break",";","case","\'minute\'",":","$minute","=","$normalizedValue",";","break",";","case","\'second\'",":","$second","=","$normalizedValue",";","break",";","}"]',
                ['set'],
            ],
        ],
        'plugin/saiadmin/app/logic/tool/CrontabLogic.php' => [
            [
                '["}","case","2",":"]',
                '["}","break",";","case","2",":"]',
                ['run'],
            ],
            [
                '["}","case","3",":"]',
                '["}","break",";","case","3",":"]',
                ['run'],
            ],
            [
                '["}","default",":"]',
                '["}","break",";","default",":"]',
                ['run'],
            ],
        ],
    ];

    /**
     * null keeps an explicitly known ordinary symbol rule; [] removes the old
     * tableArr compensation. Other rules name their owning methods.
     *
     * @return ?list<string>
     */
    public static function methods(string $path, string $before, string $after): ?array
    {
        if (!isset(self::RULES[$path])) { return null; }
        $before = self::tokenText($before);
        $after = self::tokenText($after);
        foreach (self::RULES[$path] as [$original, $replacement, $methods]) {
            if ($before === $original && $after === $replacement) { return $methods; }
        }
        throw new ConfigurationException('generator conversion method role is unknown: ' . $path);
    }

    public static function switchExpression(string $path, string $method, ?string $before = null): ?string
    {
        if ($before !== null && $path === 'vendor/phpmailer/phpmailer/src/PHPMailer.php'
            && in_array($method, ['encodeHeader', 'encodeQ'], true)
            && !str_starts_with(self::tokenText($before), '["case",')
        ) { return null; }
        if ($before !== null && $path === 'vendor/nesbot/carbon/src/Carbon/Traits/Date.php') {
            if ($method === 'set' && self::tokenText($before) !== '["$result","->","$name","=","$value",";"]') { return null; }
            if ($method === 'get' && !str_contains(self::tokenText($before), '"default"')) { return null; }
        }
        return match ($path . '#' . $method) {
            'vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php#buildDsnFromUrl' => '$driver',
            'vendor/symfony/http-foundation/Request.php#create' => 'strtoupper($method)',
            'vendor/vlucas/phpdotenv/src/Parser/EntryParser.php#processToken' => '$state',
            'vendor/topthink/think-orm/src/model/concern/TimeStamp.php#getTimeTypeValue' => '$type',
            'vendor/phpmailer/phpmailer/src/PHPMailer.php#edebug' => '$this->Debugoutput',
            'vendor/phpmailer/phpmailer/src/PHPMailer.php#encodeHeader',
            'vendor/phpmailer/phpmailer/src/PHPMailer.php#encodeQ' => 'strtolower($position)',
            'vendor/nesbot/carbon/src/Carbon/Traits/Date.php#set' => '$name',
            'vendor/nesbot/carbon/src/Carbon/Traits/Date.php#get' => 'true',
            'plugin/saiadmin/app/logic/tool/CrontabLogic.php#run' => '$info->type',
            default => null,
        };
    }

    private static function tokenText(string $fragment): string
    {
        $texts = [];
        foreach (token_get_all('<?php ' . $fragment) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $texts[] = is_array($token) ? $token[1] : $token;
        }
        return json_encode($texts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
