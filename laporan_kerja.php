<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "config/database.php";

if (!isset($_SESSION["karyawan_id"])) {
    header("Location: login.php");
    exit;
}

$karyawan_id = (int) $_SESSION["karyawan_id"];
$alert = null;
$base_path = strtok($_SERVER["REQUEST_URI"], "?");

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function valid_date($value)
{
    if (!is_string($value) || $value === "") {
        return false;
    }

    $date = DateTime::createFromFormat("Y-m-d", $value);
    return $date && $date->format("Y-m-d") === $value;
}

function build_url($base_path, $query, $overrides = [], $remove = [])
{
    foreach ($remove as $key) {
        unset($query[$key]);
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === "") {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $query_string = http_build_query($query);
    return $base_path . ($query_string === "" ? "" : "?" . $query_string);
}

function sanitize_return_to($query_string)
{
    $query_string = ltrim((string) $query_string, "?");
    if ($query_string === "") {
        return "";
    }

    if (!preg_match("/^[A-Za-z0-9_\\-\\.~=&%]*$/", $query_string)) {
        return "";
    }

    parse_str($query_string, $params);
    unset($params["export"], $params["action"], $params["csrf_token"], $params["laporan_id"]);
    return http_build_query($params);
}

function redirect_to($base_path, $query_string = "")
{
    header("Location: " . $base_path . ($query_string === "" ? "" : "?" . $query_string));
    exit;
}

$supports_soft_delete = false;
$column_check = mysqli_query($conn, "SHOW COLUMNS FROM laporan_kerja LIKE 'deleted_at'");
if ($column_check && mysqli_num_rows($column_check) > 0) {
    $supports_soft_delete = true;
}

$filter_q = trim($_GET["q"] ?? "");
$filter_from = trim($_GET["from"] ?? "");
$filter_to = trim($_GET["to"] ?? "");
$filter_status = trim($_GET["status"] ?? "active");
$sort = trim($_GET["sort"] ?? "tanggal_desc");
$page = (int) ($_GET["page"] ?? 1);
$per_page = (int) ($_GET["per_page"] ?? 10);

$allowed_status = ["active", "deleted", "all"];
if (!in_array($filter_status, $allowed_status, true)) {
    $filter_status = "active";
}
if (!$supports_soft_delete && $filter_status !== "active") {
    $filter_status = "active";
}

$allowed_sort = [
    "tanggal_desc" => "tanggal DESC, id DESC",
    "tanggal_asc" => "tanggal ASC, id ASC",
];
if (!isset($allowed_sort[$sort])) {
    $sort = "tanggal_desc";
}
$order_sql = $allowed_sort[$sort];

$allowed_per_page = [10, 20, 50];
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 10;
}
if ($page < 1) {
    $page = 1;
}

if (!valid_date($filter_from)) {
    $filter_from = "";
}
if (!valid_date($filter_to)) {
    $filter_to = "";
}

$deleted_condition_sql = "1=1";
if ($supports_soft_delete) {
    if ($filter_status === "active") {
        $deleted_condition_sql = "deleted_at IS NULL";
    } elseif ($filter_status === "deleted") {
        $deleted_condition_sql = "deleted_at IS NOT NULL";
    }
}

$listing_query = $_GET;
unset($listing_query["edit_id"], $listing_query["export"], $listing_query["page"]);
$listing_query_string = http_build_query($listing_query);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION["csrf_token"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    if (!hash_equals($csrf_token, $token)) {
        $alert = ["type" => "danger", "text" => "Token keamanan tidak valid. Silakan refresh halaman."];
    } else {
        $action = $_POST["action"] ?? "create";
        $return_to = sanitize_return_to($_POST["return_to"] ?? $listing_query_string);

        if ($action === "create") {
            $tanggal = trim($_POST["tanggal"] ?? "");
            $pekerjaan = trim($_POST["pekerjaan"] ?? "");

            if ($tanggal === "" || $pekerjaan === "") {
                $alert = ["type" => "danger", "text" => "Tanggal dan pekerjaan wajib diisi."];
            } elseif (!valid_date($tanggal)) {
                $alert = ["type" => "danger", "text" => "Format tanggal tidak valid."];
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO laporan_kerja (karyawan_id, tanggal, pekerjaan) VALUES (?, ?, ?)");
                if (!$stmt) {
                    $alert = ["type" => "danger", "text" => "Gagal menyiapkan query simpan."];
                } else {
                    mysqli_stmt_bind_param($stmt, "iss", $karyawan_id, $tanggal, $pekerjaan);
                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION["flash"] = ["type" => "success", "text" => "Laporan berhasil disimpan."];
                        redirect_to($base_path, $return_to);
                    }
                    $alert = ["type" => "danger", "text" => "Gagal menyimpan laporan."];
                    mysqli_stmt_close($stmt);
                }
            }
        } elseif ($action === "update") {
            $laporan_id = (int) ($_POST["laporan_id"] ?? 0);
            $tanggal = trim($_POST["tanggal"] ?? "");
            $pekerjaan = trim($_POST["pekerjaan"] ?? "");

            if ($laporan_id < 1 || $tanggal === "" || $pekerjaan === "") {
                $alert = ["type" => "danger", "text" => "Data update tidak lengkap."];
            } elseif (!valid_date($tanggal)) {
                $alert = ["type" => "danger", "text" => "Format tanggal tidak valid."];
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE laporan_kerja
                     SET tanggal = ?, pekerjaan = ?
                     WHERE id = ? AND karyawan_id = ? " . ($supports_soft_delete ? "AND deleted_at IS NULL" : "")
                );
                if (!$stmt) {
                    $alert = ["type" => "danger", "text" => "Gagal menyiapkan query update."];
                } else {
                    mysqli_stmt_bind_param($stmt, "ssii", $tanggal, $pekerjaan, $laporan_id, $karyawan_id);
                    mysqli_stmt_execute($stmt);
                    if (mysqli_stmt_affected_rows($stmt) >= 0) {
                        $_SESSION["flash"] = ["type" => "success", "text" => "Laporan berhasil diperbarui."];
                        redirect_to($base_path, $return_to);
                    }
                    $alert = ["type" => "danger", "text" => "Gagal memperbarui laporan."];
                    mysqli_stmt_close($stmt);
                }
            }
        } elseif ($action === "delete") {
            $laporan_id = (int) ($_POST["laporan_id"] ?? 0);
            if ($laporan_id < 1) {
                $alert = ["type" => "danger", "text" => "ID laporan tidak valid."];
            } else {
                $delete_sql = $supports_soft_delete
                    ? "UPDATE laporan_kerja SET deleted_at = NOW() WHERE id = ? AND karyawan_id = ? AND deleted_at IS NULL"
                    : "DELETE FROM laporan_kerja WHERE id = ? AND karyawan_id = ?";
                $stmt = mysqli_prepare($conn, $delete_sql);
                if (!$stmt) {
                    $alert = ["type" => "danger", "text" => "Gagal menyiapkan query hapus."];
                } else {
                    mysqli_stmt_bind_param($stmt, "ii", $laporan_id, $karyawan_id);
                    mysqli_stmt_execute($stmt);
                    if (mysqli_stmt_affected_rows($stmt) > 0) {
                        $_SESSION["flash"] = [
                            "type" => "success",
                            "text" => $supports_soft_delete ? "Laporan dipindahkan ke arsip." : "Laporan berhasil dihapus."
                        ];
                    } else {
                        $_SESSION["flash"] = ["type" => "warning", "text" => "Laporan tidak ditemukan atau bukan milik Anda."];
                    }
                    mysqli_stmt_close($stmt);
                    redirect_to($base_path, $return_to);
                }
            }
        } elseif ($action === "restore" && $supports_soft_delete) {
            $laporan_id = (int) ($_POST["laporan_id"] ?? 0);
            if ($laporan_id < 1) {
                $alert = ["type" => "danger", "text" => "ID laporan tidak valid."];
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE laporan_kerja SET deleted_at = NULL WHERE id = ? AND karyawan_id = ? AND deleted_at IS NOT NULL"
                );
                if (!$stmt) {
                    $alert = ["type" => "danger", "text" => "Gagal menyiapkan query restore."];
                } else {
                    mysqli_stmt_bind_param($stmt, "ii", $laporan_id, $karyawan_id);
                    mysqli_stmt_execute($stmt);
                    if (mysqli_stmt_affected_rows($stmt) > 0) {
                        $_SESSION["flash"] = ["type" => "success", "text" => "Laporan berhasil dipulihkan."];
                    } else {
                        $_SESSION["flash"] = ["type" => "warning", "text" => "Laporan tidak ditemukan di arsip."];
                    }
                    mysqli_stmt_close($stmt);
                    redirect_to($base_path, $return_to);
                }
            }
        }
    }
}

if (isset($_SESSION["flash"])) {
    $alert = $_SESSION["flash"];
    unset($_SESSION["flash"]);
}

$edit_data = null;
$edit_id = (int) ($_GET["edit_id"] ?? 0);
if ($edit_id > 0) {
    $stmt_edit = mysqli_prepare(
        $conn,
        "SELECT id, tanggal, pekerjaan " . ($supports_soft_delete ? ", deleted_at " : "") . "
         FROM laporan_kerja
         WHERE id = ? AND karyawan_id = ? " . ($supports_soft_delete ? "AND deleted_at IS NULL" : "") . "
         LIMIT 1"
    );
    if ($stmt_edit) {
        mysqli_stmt_bind_param($stmt_edit, "ii", $edit_id, $karyawan_id);
        mysqli_stmt_execute($stmt_edit);
        $result_edit = mysqli_stmt_get_result($stmt_edit);
        $edit_data = mysqli_fetch_assoc($result_edit);
        mysqli_stmt_close($stmt_edit);
    }
}

$export = trim($_GET["export"] ?? "");
if (in_array($export, ["csv", "pdf"], true)) {
    $sql_export = "SELECT id, tanggal, pekerjaan " . ($supports_soft_delete ? ", deleted_at " : "") . "
                   FROM laporan_kerja
                   WHERE karyawan_id = ?
                     AND (? = '' OR pekerjaan LIKE CONCAT('%', ?, '%'))
                     AND (? = '' OR tanggal >= ?)
                     AND (? = '' OR tanggal <= ?)
                     AND " . $deleted_condition_sql . "
                   ORDER BY " . $order_sql;
    $stmt_export = mysqli_prepare($conn, $sql_export);
    $export_rows = [];

    if ($stmt_export) {
        mysqli_stmt_bind_param(
            $stmt_export,
            "issssss",
            $karyawan_id,
            $filter_q,
            $filter_q,
            $filter_from,
            $filter_from,
            $filter_to,
            $filter_to
        );
        mysqli_stmt_execute($stmt_export);
        $result_export = mysqli_stmt_get_result($stmt_export);
        while ($item = mysqli_fetch_assoc($result_export)) {
            $export_rows[] = $item;
        }
        mysqli_stmt_close($stmt_export);
    }

    $filename_base = "laporan_kerja_" . date("Ymd_His");
    if ($export === "csv") {
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"" . $filename_base . ".csv\"");
        $out = fopen("php://output", "w");
        fwrite($out, "\xEF\xBB\xBF");

        $headers = ["No", "Tanggal", "Pekerjaan"];
        if ($supports_soft_delete) {
            $headers[] = "Status";
        }
        fputcsv($out, $headers);

        $no_export = 1;
        foreach ($export_rows as $item) {
            $line = [$no_export++, $item["tanggal"], $item["pekerjaan"]];
            if ($supports_soft_delete) {
                $line[] = empty($item["deleted_at"]) ? "Aktif" : "Arsip";
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    if ($export === "pdf") {
        header("Content-Type: text/html; charset=UTF-8");
        ?>
        <!doctype html>
        <html lang="id">
        <head>
            <meta charset="utf-8">
            <title>Print Laporan Kerja</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
                h2 { margin: 0 0 4px; }
                .meta { margin-bottom: 16px; font-size: 13px; color: #555; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #999; padding: 8px; vertical-align: top; }
                th { background: #f0f0f0; text-align: left; }
                .small { width: 50px; }
            </style>
        </head>
        <body>
            <h2>Laporan Kerja</h2>
            <div class="meta">
                Dicetak: <?php echo h(date("d-m-Y H:i")); ?> |
                Filter tanggal: <?php echo h($filter_from ?: "-"); ?> s/d <?php echo h($filter_to ?: "-"); ?> |
                Pencarian: <?php echo h($filter_q ?: "-"); ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th class="small">No</th>
                        <th style="width:150px;">Tanggal</th>
                        <th>Pekerjaan</th>
                        <?php if ($supports_soft_delete): ?>
                            <th style="width:120px;">Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($export_rows) === 0): ?>
                        <tr><td colspan="<?php echo $supports_soft_delete ? "4" : "3"; ?>">Data tidak ada.</td></tr>
                    <?php else: ?>
                        <?php $no_print = 1; ?>
                        <?php foreach ($export_rows as $item): ?>
                            <tr>
                                <td><?php echo $no_print++; ?></td>
                                <td><?php echo h($item["tanggal"]); ?></td>
                                <td><?php echo nl2br(h($item["pekerjaan"])); ?></td>
                                <?php if ($supports_soft_delete): ?>
                                    <td><?php echo empty($item["deleted_at"]) ? "Aktif" : "Arsip"; ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <script>window.print();</script>
        </body>
        </html>
        <?php
        exit;
    }
}

$stmt_count = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM laporan_kerja
     WHERE karyawan_id = ?
       AND (? = '' OR pekerjaan LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR tanggal >= ?)
       AND (? = '' OR tanggal <= ?)
       AND " . $deleted_condition_sql
);
$total_rows = 0;
if ($stmt_count) {
    mysqli_stmt_bind_param(
        $stmt_count,
        "issssss",
        $karyawan_id,
        $filter_q,
        $filter_q,
        $filter_from,
        $filter_from,
        $filter_to,
        $filter_to
    );
    mysqli_stmt_execute($stmt_count);
    $result_count = mysqli_stmt_get_result($stmt_count);
    $count_data = mysqli_fetch_assoc($result_count);
    $total_rows = (int) ($count_data["total"] ?? 0);
    mysqli_stmt_close($stmt_count);
}

$total_pages = max(1, (int) ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$stmt_list = mysqli_prepare(
    $conn,
    "SELECT id, tanggal, pekerjaan " . ($supports_soft_delete ? ", deleted_at " : "") . "
     FROM laporan_kerja
     WHERE karyawan_id = ?
       AND (? = '' OR pekerjaan LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR tanggal >= ?)
       AND (? = '' OR tanggal <= ?)
       AND " . $deleted_condition_sql . "
     ORDER BY " . $order_sql . "
     LIMIT ?, ?"
);
$rows = [];

if ($stmt_list) {
    mysqli_stmt_bind_param(
        $stmt_list,
        "issssssii",
        $karyawan_id,
        $filter_q,
        $filter_q,
        $filter_from,
        $filter_from,
        $filter_to,
        $filter_to,
        $offset,
        $per_page
    );
    mysqli_stmt_execute($stmt_list);
    $result_list = mysqli_stmt_get_result($stmt_list);
    while ($item = mysqli_fetch_assoc($result_list)) {
        $rows[] = $item;
    }
    mysqli_stmt_close($stmt_list);
}

$query_for_links = $_GET;
unset($query_for_links["edit_id"], $query_for_links["export"]);

include "layout/header.php";
?>

<div class="content-wrapper">
    <section class="content pt-3">
        <div class="container-fluid">

            <?php if ($alert): ?>
                <div class="alert alert-<?php echo h($alert["type"]); ?>">
                    <?php echo h($alert["text"]); ?>
                </div>
            <?php endif; ?>
            <?php if (!$supports_soft_delete): ?>
                <div class="alert alert-warning">
                    Mode soft delete belum aktif karena kolom <strong>deleted_at</strong> belum ada di tabel <strong>laporan_kerja</strong>.
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h4><?php echo $edit_data ? "Edit Laporan Kerja" : "Input Laporan Kerja"; ?></h4>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                        <input type="hidden" name="return_to" value="<?php echo h($listing_query_string); ?>">
                        <?php if ($edit_data): ?>
                            <input type="hidden" name="laporan_id" value="<?php echo (int) $edit_data["id"]; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label>Tanggal</label>
                            <input
                                type="date"
                                name="tanggal"
                                class="form-control"
                                required
                                value="<?php echo h($edit_data["tanggal"] ?? date("Y-m-d")); ?>"
                            >
                        </div>

                        <div class="mb-3">
                            <label>Deskripsi Pekerjaan</label>
                            <textarea name="pekerjaan" class="form-control" required><?php echo h($edit_data["pekerjaan"] ?? ""); ?></textarea>
                        </div>

                        <?php if ($edit_data): ?>
                            <button type="submit" name="action" value="update" class="btn btn-warning">
                                Update Laporan
                            </button>
                            <a href="<?php echo h(build_url($base_path, $_GET, ["edit_id" => null], [])); ?>" class="btn btn-secondary">
                                Batal Edit
                            </a>
                        <?php else: ?>
                            <button type="submit" name="action" value="create" class="btn btn-primary">
                                Simpan Laporan
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h4>Filter & Pencarian</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Dari Tanggal</label>
                            <input type="date" name="from" class="form-control" value="<?php echo h($filter_from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label>Sampai Tanggal</label>
                            <input type="date" name="to" class="form-control" value="<?php echo h($filter_to); ?>">
                        </div>
                        <div class="col-md-4">
                            <label>Cari Pekerjaan</label>
                            <input
                                type="text"
                                name="q"
                                class="form-control"
                                value="<?php echo h($filter_q); ?>"
                                placeholder="Contoh: meeting, deploy, revisi"
                            >
                        </div>
                        <div class="col-md-2">
                            <label>Status</label>
                            <select name="status" class="form-control" <?php echo $supports_soft_delete ? "" : "disabled"; ?>>
                                <option value="active" <?php echo $filter_status === "active" ? "selected" : ""; ?>>Aktif</option>
                                <option value="deleted" <?php echo $filter_status === "deleted" ? "selected" : ""; ?>>Arsip</option>
                                <option value="all" <?php echo $filter_status === "all" ? "selected" : ""; ?>>Semua</option>
                            </select>
                            <?php if (!$supports_soft_delete): ?>
                                <input type="hidden" name="status" value="active">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2">
                            <label>Urutkan</label>
                            <select name="sort" class="form-control">
                                <option value="tanggal_desc" <?php echo $sort === "tanggal_desc" ? "selected" : ""; ?>>Tanggal Terbaru</option>
                                <option value="tanggal_asc" <?php echo $sort === "tanggal_asc" ? "selected" : ""; ?>>Tanggal Terlama</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Data / Halaman</label>
                            <select name="per_page" class="form-control">
                                <option value="10" <?php echo $per_page === 10 ? "selected" : ""; ?>>10</option>
                                <option value="20" <?php echo $per_page === 20 ? "selected" : ""; ?>>20</option>
                                <option value="50" <?php echo $per_page === 50 ? "selected" : ""; ?>>50</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-info me-2">Filter</button>
                            <a href="<?php echo h($base_path); ?>" class="btn btn-light border">Reset</a>
                        </div>
                    </form>
                    <div class="mt-3">
                        <a
                            href="<?php echo h(build_url($base_path, $query_for_links, ["export" => "csv", "page" => null], ["edit_id"])); ?>"
                            class="btn btn-success btn-sm"
                        >
                            Export Excel (CSV)
                        </a>
                        <a
                            href="<?php echo h(build_url($base_path, $query_for_links, ["export" => "pdf", "page" => null], ["edit_id"])); ?>"
                            target="_blank"
                            class="btn btn-secondary btn-sm"
                        >
                            Export PDF (Print)
                        </a>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h4>Riwayat Laporan (<?php echo (int) $total_rows; ?> data)</h4>
                </div>

                <div class="card-body">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th width="50">No</th>
                                <th width="150">Tanggal</th>
                                <th>Pekerjaan</th>
                                <?php if ($supports_soft_delete): ?>
                                    <th width="100">Status</th>
                                <?php endif; ?>
                                <th width="220">Aksi</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($rows) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo $supports_soft_delete ? "5" : "4"; ?>" class="text-center">Belum ada laporan yang cocok dengan filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php $is_deleted = $supports_soft_delete && !empty($row["deleted_at"]); ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo h($row["tanggal"]); ?></td>
                                        <td><?php echo nl2br(h($row["pekerjaan"])); ?></td>
                                        <?php if ($supports_soft_delete): ?>
                                            <td>
                                                <?php if ($is_deleted): ?>
                                                    <span class="badge bg-secondary">Arsip</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Aktif</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if (!$is_deleted): ?>
                                                <a
                                                    class="btn btn-sm btn-warning"
                                                    href="<?php echo h(build_url($base_path, $_GET, ["edit_id" => (int) $row["id"]], [])); ?>"
                                                >
                                                    Edit
                                                </a>
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Yakin ingin mengarsipkan laporan ini?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                                                    <input type="hidden" name="return_to" value="<?php echo h(http_build_query($_GET)); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="laporan_id" value="<?php echo (int) $row["id"]; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <?php echo $supports_soft_delete ? "Arsipkan" : "Hapus"; ?>
                                                    </button>
                                                </form>
                                            <?php elseif ($supports_soft_delete): ?>
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Pulihkan laporan ini dari arsip?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                                                    <input type="hidden" name="return_to" value="<?php echo h(http_build_query($_GET)); ?>">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="laporan_id" value="<?php echo (int) $row["id"]; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">Pulihkan</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Pagination laporan">
                            <ul class="pagination mb-0">
                                <?php $prev_page = max(1, $page - 1); ?>
                                <li class="page-item <?php echo $page <= 1 ? "disabled" : ""; ?>">
                                    <a class="page-link" href="<?php echo h(build_url($base_path, $query_for_links, ["page" => $prev_page], [])); ?>">
                                        &laquo; Prev
                                    </a>
                                </li>
                                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                    <li class="page-item <?php echo $p === $page ? "active" : ""; ?>">
                                        <a class="page-link" href="<?php echo h(build_url($base_path, $query_for_links, ["page" => $p], [])); ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <?php $next_page = min($total_pages, $page + 1); ?>
                                <li class="page-item <?php echo $page >= $total_pages ? "disabled" : ""; ?>">
                                    <a class="page-link" href="<?php echo h(build_url($base_path, $query_for_links, ["page" => $next_page], [])); ?>">
                                        Next &raquo;
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include "layout/footer.php"; ?>
