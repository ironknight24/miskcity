/* ===================== File Upload ===================== */

async function fileUpload(fileData, inputName) {
  const file = fileData?.files?.[0];
  const errorMsg = document.querySelector(".error-msg");

  if (!file) {
    showError(errorMsg);
    throw new Error("No file selected");
  }

  const fileName = file.name;

  if (hasMultipleExtensions(fileName)) {
    alert("Multiple file extensions not allowed");
    return;
  }

  const extFile = getFileExtension(fileName);
  const fileType = getFileType(extFile);

  if (!fileType.isValid) {
    showError(errorMsg);
    throw new Error("Invalid file type");
  }

  const formData = buildFormData(file, extFile, inputName);
  const url = drupalSettings.globalVariables.webportalUrl + "fileupload";

  return sendUploadRequest(url, formData, inputName, errorMsg);
}

/* ===================== Helpers ===================== */

function hasMultipleExtensions(fileName) {
  return fileName.split(".").length > 2;
}

function getFileExtension(fileName) {
  const lastDotIndex = fileName.lastIndexOf(".");
  return lastDotIndex === -1 ? "" : fileName.slice(lastDotIndex + 1).toLowerCase();
}

function getFileType(ext) {
  if (["jpg", "jpeg", "png"].includes(ext)) {
    return { isValid: true, typeVal: 2, type: "image" };
  }

  if (["pdf", "doc", "docx", "mp4"].includes(ext)) {
    return { isValid: true, typeVal: 4, type: "file" };
  }

  return { isValid: false };
}

function buildFormData(file, extFile, inputName) {
  const formData = new FormData();
  const filename = `portal_${Date.now()}.${extFile}`;

  formData.append("uploadedfile1", file, filename);
  formData.append("success_action_status", "200");
  formData.append("userPic", inputName);

  return formData;
}

/* ===================== Request ===================== */

function sendUploadRequest(url, formData, inputName, errorMsg) {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open("POST", url, true);

    request.onreadystatechange = () => {
      if (request.readyState !== 4) return;

      if (request.status !== 200) {
        showError(errorMsg);
        reject(new Error(`Upload failed with status ${request.status}`));
        return;
      }

      try {
        const responseObject = JSON.parse(request.responseText);
        handleSuccessResponse(responseObject, inputName);
        resolve(responseObject);
      } catch (error) {
        showError(errorMsg);
        reject(error);
      }
    };

    request.send(formData);
  });
}

/* ===================== UI ===================== */

function handleSuccessResponse(response, inputName) {
  const box = document.querySelector(".successOrFailure");
  box?.classList.remove("hidden");

  if (inputName === "profilePic") {
    updateProfileImage(response.profilePic);
    showSuccessMessage("Profile Added successfully");
  } else {
    showFailureMessage("Profile not added successfully");
    setHiddenValues(inputName, response);
  }

  setTimeout(() => box?.classList.add("hidden"), 5000);
}

function updateProfileImage(src) {
  document.querySelector(".profilePicSrc")?.setAttribute("src", src);
}

function setHiddenValues(inputName, response) {
  document.getElementById(`${inputName}_name`).value = response.fileName;
  document.getElementById(`${inputName}_id`).value = response.fileTypeId;
  document.getElementById(`${inputName}_type`).value = response.fileTypeVal;
}

function showError(errorMsg) {
  errorMsg?.classList.remove("hidden");
}

function showSuccessMessage(message) {
  document.querySelector(".update-img").src =
    "themes/custom/engage_theme/images/Profile/change-success.png";
  document.querySelector(".update-msg").innerHTML =
    `<p class="text-[#00AB26]">${message}</p>`;
}

function showFailureMessage(message) {
  document.querySelector(".update-img").src =
    "themes/custom/engage_theme/images/Profile/failure.svg";
  document.querySelector(".update-msg").innerHTML =
    `<p class="text-[#de001b]">${message}</p>`;
}
