function uploadFiles() {
    const files = document.getElementById('fileInput').files;
    const progressBar = document.getElementById('progressBar');
    const status = document.getElementById('status');
    const fileTableBody = document.getElementById('fileTableBody');

    if (files.length === 0) {
        alert('Please select a file.');
        return;
    }

    let completedUploads = 0;
    const totalFiles = files.length;

    const updateOverallStatus = () => {
        if (completedUploads === totalFiles) {
            const failedUploads = Array.from(document.querySelectorAll('[id^="status-"]'))
                .some(el => el.textContent === 'Failed');
            status.textContent = failedUploads ? 'Upload failed. Please try again.' : 'All files uploaded successfully!';
        }
    };

    const uploadFile = function(file, index) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const formData = new FormData();
            formData.append('files[]', file);

            xhr.open('POST', 'upload.php', true);

            xhr.upload.onprogress = function(event) {
                if (event.lengthComputable) {
                    const percentComplete = (event.loaded / event.total) * 100;
                    progressBar.style.width = `${percentComplete}%`;
                }
            };

            xhr.onload = function() {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        const filePath = response.filePaths[0]; 
                        const linkElement = document.getElementById(`file-link-${index + 1}`);
                        linkElement.href = filePath; 
                        linkElement.textContent = file.name; 
                        document.getElementById(`status-${index + 1}`).textContent = 'Uploaded';
                        resolve();
                    } else {
                        document.getElementById(`status-${index + 1}`).textContent = 'Failed';
                        reject();
                    }
                } else {
                    document.getElementById(`status-${index + 1}`).textContent = 'Failed';
                    reject();
                }
            };

            xhr.onerror = function() {
                document.getElementById(`status-${index + 1}`).textContent = 'Failed';
                reject();
            };

            xhr.send(formData);
        });
    };

    fileTableBody.innerHTML = '';
    for (let i = 0; i < files.length; i++) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${i + 1}</td>
            <td><a id="file-link-${i + 1}" href="#" target="_blank">${files[i].name}</a></td>
            <td id="status-${i + 1}">Uploading...</td>
        `;
        fileTableBody.appendChild(row);

        uploadFile(files[i], i).finally(() => {
            completedUploads++;
            updateOverallStatus();
        });
    }
}
