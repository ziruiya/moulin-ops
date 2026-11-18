# CI Pint 修复报告

## 基线与复现

- 基线：`main` / `917bc754426842de97226d6b74861d13af6da6ed`
- 使用一次性 Alpine 容器（PHP 8.5）通过 HTTPS 克隆 `https://github.com/ziruiya/moulin-ops.git`，checkout `917bc75`，然后执行 `composer install --no-interaction --prefer-dist`。
- 初次 `vendor/bin/pint --test` 结果：`FAIL — 43 files, 1 style issue`。
- Pint 完整报告：`app/Policies/AssetPolicy.php` — `method_argument_space`。

## 根因

`AssetPolicy::delete()` 的调用参数在逗号后缺少空格：`$this->has($u,'Admin')`。Pint 规则要求 `$this->has($u, 'Admin')`。这是源码格式问题，不是 Pint 配置或检查文件集问题；没有改动 `pint.json`。

干净 Linux checkout 稳定复现了该问题。另在修复前对当前挂载自 `D:\moulin-build` 的项目容器执行单文件检查，也得到相同失败。因此，本次无法重现“同一当前工作树此前 Pint 通过”的观察；该先前通过结果应来自不同的文件状态或检查上下文，当前证据无法进一步区分。换行差异不是本次失败的必要原因。

## 改动

- `app/Policies/AssetPolicy.php`：仅在 `delete()` 中补齐参数逗号后的空格。

## 验证

修复文件同步到前述干净 Linux clone 后：

```text
PASS — 43 files
```

当前工作树的 `moulinops-app-1` 容器中：

```text
vendor/bin/pint --test: PASS — 43 files
php artisan test: PASS — 15 tests, 63 assertions
```

未推送。完成后删除了一次性复现容器及其 clone。
