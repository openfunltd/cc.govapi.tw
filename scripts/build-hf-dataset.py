#!/usr/bin/env python3
"""
把 scripts/export-dataset.php 匯出的 JSONL 轉成 Parquet，並產生 HuggingFace 資料集說明（README.md），
可選擇直接上傳。

用法：
  python3 scripts/build-hf-dataset.py {匯出目錄} {輸出目錄} [--types=a,b] [--upload] [--private] [--repo=openfun/tw-cc-data]

- 全部型別放在同一個資料集 repo（預設 openfun/tw-cc-data），每個型別一個 subset（config），
  依議會分檔的型別另有 {型別}-{議會代碼} 的單一議會 subset
- --private：新建 repo 時設為私人（已存在的 repo 不改變公開狀態）
- 欄位型態依全部資料推斷：整數/浮點/布林/字串維持原型態，混合型態的純量轉成字串，
  字串陣列保留成 list<string>，物件與物件陣列存成 JSON 字串
- 上傳需要 huggingface_hub 與寫入權限的 token（HF_TOKEN 環境變數或 hf auth login）
"""
import argparse
import json
import os
import sys

import pyarrow as pa
import pyarrow.parquet as pq

SITE = 'https://all.cc.govapi.tw'
ROW_GROUP = 50000


def classify(values_types, list_item_types):
    types = set(values_types) - {'null'}
    if not types:
        return 'string'
    if types == {'array'}:
        items = set(list_item_types) - {'null'}
        if items <= {'string'}:
            return 'list_string'
        return 'json'
    if 'array' in types or 'object' in types:
        return 'json'
    if types == {'integer'}:
        return 'int'
    if types <= {'integer', 'number'}:
        return 'float'
    if types == {'boolean'}:
        return 'bool'
    return 'string'


ARROW = {
    'string': pa.string(),
    'list_string': pa.list_(pa.string()),
    'json': pa.string(),
    'int': pa.int64(),
    'float': pa.float64(),
    'bool': pa.bool_(),
}
TYPE_LABEL = {
    'string': 'string',
    'list_string': 'list<string>',
    'json': 'string（JSON）',
    'int': 'int64',
    'float': 'float64',
    'bool': 'bool',
}


def json_type(v):
    if v is None:
        return 'null'
    if isinstance(v, bool):
        return 'boolean'
    if isinstance(v, int):
        return 'integer'
    if isinstance(v, float):
        return 'number'
    if isinstance(v, str):
        return 'string'
    if isinstance(v, list):
        return 'array'
    return 'object'


def convert(v, kind):
    if v is None:
        return None
    if kind == 'json':
        return json.dumps(v, ensure_ascii=False)
    if kind == 'list_string':
        return [None if x is None else str(x) for x in v]
    if kind == 'string':
        return v if isinstance(v, str) else json.dumps(v, ensure_ascii=False)
    if kind == 'float':
        return float(v)
    return v


def scan_schema(paths, field_order):
    item_types = {k: set() for k in field_order}
    for path in paths:
        with open(path, encoding='utf-8') as fh:
            for line in fh:
                doc = json.loads(line)
                for k, v in doc.items():
                    if isinstance(v, list):
                        item_types.setdefault(k, set()).update(json_type(x) for x in v)
    return item_types


def write_parquet(src, dst, kinds):
    schema = pa.schema([(k, ARROW[kind]) for k, kind in kinds.items()])
    writer = pq.ParquetWriter(dst, schema, compression='zstd')
    rows = 0
    batch = {k: [] for k in kinds}

    def flush():
        writer.write_table(pa.table(batch, schema=schema))
        for k in batch:
            batch[k] = []

    with open(src, encoding='utf-8') as fh:
        for line in fh:
            doc = json.loads(line)
            for k, kind in kinds.items():
                batch[k].append(convert(doc.get(k), kind))
            rows += 1
            if rows % ROW_GROUP == 0:
                flush()
    if rows % ROW_GROUP or rows == 0:
        flush()
    writer.close()
    return rows


def md_escape(s):
    return str(s).replace('|', '\\|').replace('\n', ' ')


# README 與 subset 的排列順序：議會 → 人 → 議事 → 選舉 → 行政
TYPE_ORDER = ['council', 'term', 'councilor', 'session', 'sitting', 'committee', 'bill',
              'meet', 'meet_note', 'meet_transcript', 'candidate', 'agency']


def build_readme(datasets, council_names, repo):
    lines = ['---', 'license: cc-by-4.0', 'language:', '- zh', 'tags:', '- taiwan', '- council', '- open-government',
             'configs:']
    for d in datasets:
        t = d['meta']['type']
        if d['files'] == ['data']:
            lines += [f'- config_name: {t}', f'  data_files: {t}/data.parquet']
        else:
            lines += [f'- config_name: {t}', f'  data_files: {t}/*.parquet']
            for f in d['files']:
                lines += [f'- config_name: {t}-{f}', f'  data_files: {t}/{f}.parquet']
    lines += ['---', '']
    lines.append('# 台灣各級議會資料（tw-cc-data）')
    lines.append('')
    lines.append(f'歐噴資料庫「議會 API」（[cc.govapi.tw]({SITE})）的資料整包下載：各縣市議會的議員、會期、場次、'
                 '議案、會議逐字稿，以及歷屆選舉候選人等資料。每個型別是一個 subset，內容與對應的 API 回傳資料相同，'
                 '全部 subset 來自同一次匯出，彼此的代碼可以直接對照。')
    lines.append('')
    lines.append(f'- 網頁瀏覽與 API：{SITE}（各議會子網域，例如 https://tpe.cc.govapi.tw）')
    lines.append(f'- API 文件：{SITE}/swagger')
    lines.append(f'- 資料定義背景知識：{SITE}/knowledge.md')
    lines.append('')
    lines.append('```python')
    lines.append('from datasets import load_dataset')
    lines.append(f'ds = load_dataset("{repo}", "councilor")              # 議員')
    lines.append(f'ds = load_dataset("{repo}", "meet_transcript-tpe")    # 只載入臺北市議會的逐字稿')
    lines.append('```')
    lines.append('')
    lines.append('## Subset 一覽')
    lines.append('')
    lines.append('|subset|資料|筆數|對應 API|')
    lines.append('|---|---|---|---|')
    for d in datasets:
        m = d['meta']
        split = '（依議會分檔）' if d['files'] != ['data'] else ''
        lines.append(f'|[{m["type"]}](#{m["type"]})|{m["subject"]}{split}|{sum(d["rows"].values()):,}|`/api/{m["endpoint"]}`|')
    lines.append('')
    lines.append('型態為「string（JSON）」的欄位是巢狀資料，存成 JSON 字串，使用時再 `json.loads()`。')
    lines.append('')
    for d in datasets:
        m = d['meta']
        lines.append(f'## {m["type"]}')
        lines.append('')
        lines.append(f'**{m["subject"]}**，共 {sum(d["rows"].values()):,} 筆。')
        lines.append('')
        if d['files'] != ['data']:
            lines.append(f'依議會代碼分檔，可用 `{m["type"]}-{{議會代碼}}` 只載入單一議會：')
            lines.append('')
            lines.append('|議會代碼|議會|筆數|')
            lines.append('|---|---|---|')
            for f in d['files']:
                lines.append(f'|{f}|{md_escape(council_names.get(f, ""))}|{d["rows"][f]:,}|')
            lines.append('')
        lines.append('|欄位|型態|有值筆數|說明|')
        lines.append('|---|---|---|---|')
        for k, info in m['fields'].items():
            lines.append(f'|{md_escape(k)}|{TYPE_LABEL[d["kinds"][k]]}|{info["count"]:,}|{md_escape(info["description"])}|')
        lines.append('')
    lines.append('## 授權')
    lines.append('')
    lines.append('本資料集採 [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/deed.zh-hant) 授權，'
                 '使用時請標示「歐噴資料庫」。原始資料來自各議會官網、中央選舉委員會等政府公開資料，'
                 '經歐噴整理、清理與跨來源對照；如發現資料錯誤，歡迎回報。')
    lines.append('')
    return '\n'.join(lines)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('export_dir')
    ap.add_argument('out_dir')
    ap.add_argument('--types')
    ap.add_argument('--repo', default='openfun/tw-cc-data')
    ap.add_argument('--upload', action='store_true')
    ap.add_argument('--private', action='store_true')
    args = ap.parse_args()

    council_names = {}
    council_path = os.path.join(args.export_dir, 'council', 'data.jsonl')
    if os.path.exists(council_path):
        with open(council_path, encoding='utf-8') as fh:
            for line in fh:
                d = json.loads(line)
                council_names[d['代碼']] = d.get('議會名稱', '')

    types = [t for t in TYPE_ORDER if os.path.exists(os.path.join(args.export_dir, t, 'fields.json'))]
    if args.types:
        types = [t for t in types if t in args.types.split(',')]
    datasets = []
    for type_ in types:
        meta = json.load(open(os.path.join(args.export_dir, type_, 'fields.json'), encoding='utf-8'))
        files = sorted(meta['files'])
        srcs = {f: os.path.join(args.export_dir, type_, f + '.jsonl') for f in files}
        item_types = scan_schema(srcs.values(), meta['fields'])
        kinds = {k: classify(info['types'], item_types.get(k, ())) for k, info in meta['fields'].items()}

        out = os.path.join(args.out_dir, type_)
        os.makedirs(out, exist_ok=True)
        for f in os.listdir(out):
            os.unlink(os.path.join(out, f))
        rows = {f: write_parquet(srcs[f], os.path.join(out, f + '.parquet'), kinds) for f in files}
        datasets.append({'meta': meta, 'files': files, 'rows': rows, 'kinds': kinds})
        print(f'{type_}: {sum(rows.values()):,} 筆、{len(files)} 個檔案', flush=True)

    with open(os.path.join(args.out_dir, 'README.md'), 'w', encoding='utf-8') as fh:
        fh.write(build_readme(datasets, council_names, args.repo))

    if args.upload:
        from huggingface_hub import HfApi
        api = HfApi()
        api.create_repo(args.repo, repo_type='dataset', private=args.private, exist_ok=True)
        api.upload_folder(repo_id=args.repo, repo_type='dataset', folder_path=args.out_dir,
                          commit_message='更新資料', delete_patterns=[f'{t}/*.parquet' for t in types])
        print(f'已上傳 https://huggingface.co/datasets/{args.repo}', flush=True)


if __name__ == '__main__':
    sys.exit(main())
