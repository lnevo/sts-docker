<?php
$cargo_file = 'cargo_list.txt';

function get_cargo() {
    global $cargo_file;
    return file($cargo_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

function remove_cargo($cargo) {
    global $cargo_file;
    $cargo_list = get_cargo();
    $cargo_list = array_diff($cargo_list, [$cargo]);
    file_put_contents($cargo_file, implode("\n", $cargo_list));
}

function reset_cargo() {
    global $cargo_file;
    $original_cargo = [
        'Coal', 'Grain', 'Timber', 'Oil', 'Ore',
        'Containers', 'Automobiles', 'Livestock'
    ];
    //file_put_contents($cargo_file, implode("\n", $original_cargo));
}

function saveCargo() {
    global $cargo_file;
    // Check if the request method is POST and action is 'save'
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save') {
        // Get the JSON input from the POST request
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Validate the input
        if (isset($data['cargo']) && is_array($data['cargo'])) {
            // Save the cargo data to the file
            $cargo_data = implode("\n", $data['cargo']);
            file_put_contents($cargo_file, $cargo_data);

            // Return a success response
            echo json_encode(['success' => true]);
        } else {
            // Return an error response if the data is invalid
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
        }
        exit; // Terminate the script after handling the request
    }
}


$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        echo json_encode(get_cargo());
        break;
    case 'remove':
        $cargo = $_GET['cargo'] ?? '';
        if ($cargo) {
            remove_cargo($cargo);
        }
        echo json_encode(['success' => true]);
        break;
    case 'reset':
        reset_cargo();
        echo json_encode(['success' => true]);
        break;
    case 'save':
        saveCargo();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}
