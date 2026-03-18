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
                        header("Location: " . strtok($_SERVER["REQUEST_URI"], "?"));
                        exit;
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
                    "UPDATE laporan_kerja SET tanggal = ?, pekerjaan = ? WHERE id = ? AND karyawan_id = ?"
                );
                if (!$stmt) {
                    $alert = ["type" => "danger", "text" => "Gagal menyiapkan query update."];
                } else {
                    mysqli_stmt_bind_param($stmt, "ssii", $tanggal, $pekerjaan, $laporan_id, $karyawan_id);
                    mysqli_stmt_execute($stmt);
                    if (mysqli_stmt_affected_rows($stmt) >= 0) {
                        $_SESSION["flash"] = ["type" => "success", "text" => "Laporan berhasil diperbarui."];
                        header("Location: " . strtok($_SERVER["REQUEST_URI"], "?"));
                        exit;
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
                $stmt = mysqli_prepare($conn, "DELETE FROM laporan_kerja WHERE id = ? AND karyawan_id = ?");
                if (!$stmt) {
                    $alert = ["type" => "danger", "text" => "Gagal menyiapkan query hapus."];
                } else {
                    mysqli_stmt_bind_param($stmt, "ii", $laporan_id, $karyawan_id);
                    mysqli_stmt_execute($stmt);
                    if (mysqli_stmt_affected_rows($stmt) > 0) {
                        $_SESSION["flash"] = ["type" => "success", "text" => "Laporan berhasil dihapus."];
                    } else {
                        $_SESSION["flash"] = ["type" => "warning", "text" => "Laporan tidak ditemukan atau bukan milik Anda."];
                    }
                    mysqli_stmt_close($stmt);
                    header("Location: " . strtok($_SERVER["REQUEST_URI"], "?"));
                    exit;
                }
            }
        }
    }
}

if (isset($_SESSION["flash"])) {
    $alert = $_SESSION["flash"];
    unset($_SESSION["flash"]);
}

$filter_q = trim($_GET["q"] ?? "");
$filter_from = trim($_GET["from"] ?? "");
$filter_to = trim($_GET["to"] ?? "");

if (!valid_date($filter_from)) {
    $filter_from = "";
}
if (!valid_date($filter_to)) {
    $filter_to = "";
}

$edit_data = null;
$edit_id = (int) ($_GET["edit_id"] ?? 0);
if ($edit_id > 0) {
    $stmt_edit = mysqli_prepare(
        $conn,
        "SELECT id, tanggal, pekerjaan FROM laporan_kerja WHERE id = ? AND karyawan_id = ? LIMIT 1"
    );
    if ($stmt_edit) {
        mysqli_stmt_bind_param($stmt_edit, "ii", $edit_id, $karyawan_id);
        mysqli_stmt_execute($stmt_edit);
        $result_edit = mysqli_stmt_get_result($stmt_edit);
        $edit_data = mysqli_fetch_assoc($result_edit);
        mysqli_stmt_close($stmt_edit);
    }
}

$stmt_list = mysqli_prepare(
    $conn,
    "SELECT id, tanggal, pekerjaan
     FROM laporan_kerja
     WHERE karyawan_id = ?
       AND (? = '' OR pekerjaan LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR tanggal >= ?)
       AND (? = '' OR tanggal <= ?)
     ORDER BY tanggal DESC, id DESC"
);
$rows = [];

if ($stmt_list) {
    mysqli_stmt_bind_param(
        $stmt_list,
        "issssss",
        $karyawan_id,
        $filter_q,
        $filter_q,
        $filter_from,
        $filter_from,
        $filter_to,
        $filter_to
    );
    mysqli_stmt_execute($stmt_list);
    $result_list = mysqli_stmt_get_result($stmt_list);
    while ($item = mysqli_fetch_assoc($result_list)) {
        $rows[] = $item;
    }
    mysqli_stmt_close($stmt_list);
}

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

            <div class="card">
                <div class="card-header">
                    <h4><?php echo $edit_data ? "Edit Laporan Kerja" : "Input Laporan Kerja"; ?></h4>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
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
                            <a href="<?php echo h(strtok($_SERVER["REQUEST_URI"], "?")); ?>" class="btn btn-secondary">
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
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-info me-2">Filter</button>
                            <a href="<?php echo h(strtok($_SERVER["REQUEST_URI"], "?")); ?>" class="btn btn-light border">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h4>Riwayat Laporan</h4>
                </div>

                <div class="card-body">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th width="50">No</th>
                                <th width="150">Tanggal</th>
                                <th>Pekerjaan</th>
                                <th width="170">Aksi</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($rows) === 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center">Belum ada laporan yang cocok dengan filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo h($row["tanggal"]); ?></td>
                                        <td><?php echo nl2br(h($row["pekerjaan"])); ?></td>
                                        <td>
                                            <a
                                                class="btn btn-sm btn-warning"
                                                href="<?php echo h(strtok($_SERVER["REQUEST_URI"], "?")) . "?edit_id=" . (int) $row["id"]; ?>"
                                            >
                                                Edit
                                            </a>

                                            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Yakin ingin menghapus laporan ini?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="laporan_id" value="<?php echo (int) $row["id"]; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include "layout/footer.php"; ?>
