<?php

putenv('ELASTIC_PASSWORD=');          // ES 密碼（必填）
putenv('ELASTIC_URL=');               // ES URL（必填）
putenv('ELASTIC_USER=');              // ES 使用者（必填）
putenv('ELASTIC_PREFIX=ccv1_');       // index 前綴，例如 ccv1_councilor
putenv('CCAPI_DOMAIN_POSTFIX=.cc.govapi.tw');  // 子網域後綴

// 匯入資料來源路徑（可指定絕對路徑，供不同主機使用）
putenv('IMPORT_COUNCIL_CSV=');        // 議會.csv 路徑，預設 {project}/議會.csv
putenv('IMPORT_TERM_CSV=');           // 屆.csv 路徑，預設 {project}/屆.csv
putenv('IMPORT_COUNCILOR_JSONL=');    // 議員.jsonl 路徑，預設 {project}/議員.jsonl
putenv('IMPORT_SESSION_CSV=');        // 會期.csv 路徑，預設 {project}/會期.csv
putenv('IMPORT_COMMITTEE_CSV=');      // 委員會 data.csv 路徑，預設 {project}/data.csv
putenv('IMPORT_SITTING_CSV=');        // 場次.csv 路徑，預設 {project}/場次.csv
putenv('IMPORT_TRANSCRIPT_CSV=');     // 逐字稿索引.csv 路徑，預設 {project}/逐字稿索引.csv
putenv('IMPORT_TRANSCRIPT_BASE_DIR=');// 逐字稿原始檔案目錄（索引裡的相對路徑基準），預設 {project}/逐字稿
putenv('IMPORT_BILL_JSONL=');         // 議案.jsonl 路徑，預設 {project}/議案.jsonl
putenv('IMPORT_CANDIDATE_JSONL=');    // bulletin.jsonl 路徑，預設 {project}/bulletin.jsonl
putenv('IMPORT_CANDIDATE_VOTES_JSONL=');// 得票數.jsonl 路徑（已篩選縣市/直轄市議員、
                                       // 縣市層級得票數的子集），預設 {project}/得票數.jsonl
putenv('IMPORT_CANDIDATE_PERSON_JSONL=');// 人物代碼.jsonl 路徑（候選人代碼→人物代碼
                                       // 對照表子集），預設 {project}/人物代碼.jsonl
putenv('IMPORT_CANDIDATE_ELECTED_JSONL=');// 當選註記.jsonl 路徑（候選人代碼→中選會
                                       // 當選註記對照表子集），預設 {project}/當選註記.jsonl
