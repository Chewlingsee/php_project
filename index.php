<?php
// 1) DB Connection
$servername = "localhost";
$username   = "root";
$password   = "Clsee2344.";
$dbname     = "inventory_management";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 2) FETCH => Return JSON
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    $sql = "SELECT id, productName, quantity, category, price
            FROM inventory
            ORDER BY id ASC";
    $result = $conn->query($sql);

    $products = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }

    header('Content-Type: application/json');
    echo json_encode($products);
    $conn->close();
    exit;
}

// 3) POST => ADD / EDIT / DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $response = ['status' => 'error', 'message' => 'Unknown action'];

    switch ($action) {
        case 'add':
            // Gather inputs
            $productName = $_POST['productName'] ?? '';
            $quantity    = $_POST['quantity']    ?? 0;
            $category    = $_POST['category']    ?? '';
            $price       = $_POST['price']       ?? 0.0;

            // 1) Determine next ID (max + 1)
            $sqlMax = "SELECT COALESCE(MAX(id), 0) AS maxId FROM inventory";
            $resMax = $conn->query($sqlMax);
            $rowMax = $resMax->fetch_assoc();
            $newId  = $rowMax['maxId'] + 1;

            // 2) Insert new row using that ID
            $stmt = $conn->prepare("INSERT INTO inventory (id, productName, quantity, category, price)
                                    VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isiss", $newId, $productName, $quantity, $category, $price);
            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Item added successfully'];
            } else {
                $response['message'] = 'Error adding item: ' . $stmt->error;
            }
            $stmt->close();
            break;

        case 'edit':
            // Gather inputs
            $id          = $_POST['id']          ?? 0;
            $productName = $_POST['productName'] ?? '';
            $quantity    = $_POST['quantity']    ?? 0;
            $category    = $_POST['category']    ?? '';
            $price       = $_POST['price']       ?? 0;

            $stmt = $conn->prepare("UPDATE inventory
                                    SET productName = ?, quantity = ?, category = ?, price = ?
                                    WHERE id = ?");
            $stmt->bind_param("sissi", $productName, $quantity, $category, $price, $id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $response = ['status' => 'success', 'message' => 'Item updated successfully'];
                } else {
                    $response['message'] = 'No matching product ID to update.';
                }
            } else {
                $response['message'] = 'Error updating item: ' . $stmt->error;
            }
            $stmt->close();
            break;

        case 'delete':
            // Gather inputs
            $id = $_POST['id'] ?? 0;

            // 1) Delete
            $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    // 2) Re-sequence (close the gap)
                    $conn->query("SET @count = 0");
                    $conn->query("UPDATE inventory
                                  SET id = (@count := @count + 1)
                                  ORDER BY id ASC");

                    $response = [
                        'status'  => 'success',
                        'message' => 'Item deleted & IDs re-sequenced'
                    ];
                } else {
                    $response['message'] = 'No matching product ID to delete.';
                }
            } else {
                $response['message'] = 'Error deleting item: ' . $stmt->error;
            }
            $stmt->close();
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    $conn->close();
    exit;
}

// If no action, just close
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory</title>
</head>
<body>
    <h1>Inventory Stock</h1>
    <button onclick="openForm('add')">Add</button>
    <button onclick="deleteItem()">Delete</button>
    <button onclick="openForm('edit')">Edit</button>

    <div id="message"></div>

    <table id="inventoryTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Qty</th>
                <th>Category</th>
                <th>Price</th>
            </tr>
        </thead>
        <tbody><!-- JS populates --></tbody>
    </table>

    <div id="formPopup" style="display:none;">
        <form onsubmit="saveItem(event)">
            <h3 id="formTitle">Add Item</h3>
            <input type="hidden" id="actionType" value="add">
            <input type="hidden" id="editId">

            <label>Name:</label>
            <input type="text" id="productName" required>

            <label>Quantity:</label>
            <input type="number" id="quantity" required>

            <label>Category:</label>
            <input type="text" id="category" required>

            <label>Price:</label>
            <input type="number" step="0.01" id="price" required>

            <button type="submit">Save</button>
            <button type="button" onclick="closeForm()">Cancel</button>
        </form>
    </div>

    <script>
    // Basic JS for fetching, rendering, open/close forms, saving, and deleting
    const tableBody   = document.querySelector('#inventoryTable tbody');
    const formPopup   = document.getElementById('formPopup');
    const formTitle   = document.getElementById('formTitle');
    const actionType  = document.getElementById('actionType');
    const editId      = document.getElementById('editId');
    const productName = document.getElementById('productName');
    const quantity    = document.getElementById('quantity');
    const category    = document.getElementById('category');
    const price       = document.getElementById('price');
    const messageDiv  = document.getElementById('message');

    // Auto-load data
    window.addEventListener('DOMContentLoaded', fetchTable);

    function fetchTable() {
        fetch('?action=fetch')
            .then(res => res.json())
            .then(data => renderTable(data))
            .catch(err => console.error(err));
    }

    function renderTable(items) {
        tableBody.innerHTML = '';
        if (!items || items.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="5">No records found</td></tr>';
            return;
        }
        items.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${item.id}</td>
                <td>${item.productName}</td>
                <td>${item.quantity}</td>
                <td>${item.category}</td>
                <td>${item.price}</td>
            `;
            tableBody.appendChild(tr);
        });
    }

    function openForm(mode) {
        formPopup.style.display = 'block';
        document.querySelector('form').reset();
        editId.value = '';

        if (mode === 'add') {
            formTitle.textContent = 'Add Item';
            actionType.value = 'add';
        } else {
            const inputId = prompt('Enter ID to edit:');
            if (!inputId) { closeForm(); return; }

            fetch('?action=fetch')
                .then(res => res.json())
                .then(products => {
                    const product = products.find(p => p.id == inputId);
                    if (!product) {
                        alert('No product with that ID.');
                        closeForm();
                        return;
                    }
                    formTitle.textContent = 'Edit Item';
                    actionType.value = 'edit';
                    editId.value      = product.id;
                    productName.value = product.productName;
                    quantity.value    = product.quantity;
                    category.value    = product.category;
                    price.value       = product.price;
                })
                .catch(err => {
                    console.error(err);
                    closeForm();
                });
        }
    }

    function closeForm() {
        formPopup.style.display = 'none';
    }

    function saveItem(e) {
        e.preventDefault();
        const act = actionType.value;
        const fd  = new FormData();
        fd.append('action', act);
        fd.append('id', editId.value);
        fd.append('productName', productName.value);
        fd.append('quantity', quantity.value);
        fd.append('category', category.value);
        fd.append('price', price.value);

        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(json => {
                messageDiv.textContent = json.message;
                if (json.status === 'success') {
                    messageDiv.style.color = 'green';
                    fetchTable();
                    closeForm();
                } else {
                    messageDiv.style.color = 'red';
                }
            })
            .catch(err => console.error(err));
    }

    function deleteItem() {
        const inputId = prompt('Enter ID to delete:');
        if (!inputId) return;

        if (!confirm('Are you sure?')) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', inputId);

        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(json => {
                alert(json.message);
                if (json.status === 'success') {
                    fetchTable();
                }
            })
            .catch(err => console.error(err));
    }
    </script>
</body>
</html>
